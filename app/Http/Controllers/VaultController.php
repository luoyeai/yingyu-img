<?php

namespace App\Http\Controllers;

use App\Models\Group;
use App\Models\Image;
use App\Models\Setting;
use App\Models\StorageConfiguration;
use App\Services\EnvironmentDiagnostics;
use App\Services\ImageStorage;
use App\Services\UploadFailure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class VaultController extends Controller
{
    public function uploadPage(): View
    {
        return view('upload', ['groups' => Group::orderBy('name')->get(), 'options' => Setting::uploadOptions(), 'storages' => StorageConfiguration::where('enabled', true)->orderBy('id')->get(), 'defaultStorageId' => Setting::value('default_storage_id') ?? StorageConfiguration::where('driver', 'local')->value('id')]);
    }

    public function upload(Request $request, ImageStorage $storage): JsonResponse
    {
        $options = Setting::uploadOptions();
        $request->validate([
            'file' => ['required', 'file', 'max:'.($options['max_file_mb'] * 1024)],
            'group_id' => 'nullable|integer|exists:groups,id',
            'preset' => 'required|in:standard,high,custom',
            'storage_configuration_id' => 'nullable|integer|exists:storage_configurations,id',
            'quality' => 'nullable|integer|min:1|max:100',
            'lossless' => 'nullable|boolean',
            'max_edge' => 'nullable|integer|min:0|max:20000',
        ]);
        if ($request->preset === 'high') {
            $options = array_replace($options, ['quality' => 95, 'lossless' => false, 'max_edge' => 0]);
        }
        if ($request->preset === 'custom') {
            foreach (['quality', 'lossless', 'max_edge'] as $key) {
                if ($request->filled($key)) {
                    $options[$key] = $request->input($key);
                }
            }
        }
        try {
            $result = $storage->upload($request->file('file'), $options, $request->filled('group_id') ? (int) $request->group_id : null, $request->filled('storage_configuration_id') ? (int) $request->storage_configuration_id : null);
        } catch (UploadFailure $exception) {
            return response()->json(['message' => $exception->getMessage(), 'code' => $exception->errorCode, 'retryable' => $exception->retryable, 'scope' => $exception->scope], 503);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => '保存图片未确认，请检查存储权限、磁盘和数据库；云端未完成操作可在设置中处理。', 'code' => 'storage_error', 'retryable' => false, 'scope' => 'queue'], 503);
        }

        $image = $result['image'];

        return response()->json([
            'image' => [...$image->toArray(), 'url' => $image->url, 'preview_url' => $image->preview_url],
            'duplicate' => $result['duplicate'], 'trashed' => $result['trashed'],
        ]);
    }

    public function library(Request $request): View
    {
        $request->validate(['q' => 'nullable|string|max:255', 'group' => ['nullable', 'string', 'regex:/^(none|[0-9]+)$/'], 'date' => 'nullable|date_format:Y-m-d']);
        $trash = $request->routeIs('trash');
        $query = Image::with(['group', 'storageConfiguration']);
        if ($trash) {
            $query->onlyTrashed();
        }
        if ($request->filled('q')) {
            $search = trim($request->q);
            $query->where(fn ($builder) => $builder->where('original_name', 'like', '%'.$search.'%')->orWhere('display_name', 'like', '%'.$search.'%'));
        }
        if ($request->filled('group')) {
            $request->group === 'none' ? $query->whereNull('group_id') : $query->where('group_id', $request->group);
        }
        if ($request->filled('date')) {
            $query->whereDate('created_at', $request->date);
        }

        return view('library', ['images' => $query->latest()->paginate(30)->withQueryString(), 'groups' => Group::orderBy('name')->get(), 'trash' => $trash]);
    }

    public function edit(Request $request, Image $image, ImageStorage $storage): RedirectResponse
    {
        $data = $request->validate(['display_name' => 'required|string|max:255', 'alt' => 'nullable|string|max:500', 'group_id' => 'nullable|integer|exists:groups,id']);
        $storage->withImage($image, fn (Image $record) => $record->update($data));

        return back()->with('success', '图片信息已更新，公开链接保持不变。');
    }

    public function batch(Request $request, ImageStorage $storage): RedirectResponse
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct',
            'action' => 'required|in:trash,restore,purge,disable,enable,move,copy',
            'group_id' => 'nullable|integer|exists:groups,id', 'confirmation' => 'nullable|string',
        ]);
        $images = Image::withTrashed()->whereIn('id', $data['ids'])->orderBy('id')->get();
        if ($images->count() !== count($data['ids'])) {
            throw ValidationException::withMessages(['ids' => '所选图片已发生变化，请刷新后重试；尚未修改任何图片。']);
        }
        if ($data['action'] === 'purge' && $request->confirmation !== '永久删除') {
            throw ValidationException::withMessages(['confirmation' => '请输入“永久删除”确认所选图片将不可恢复。']);
        }
        if (in_array($data['action'], ['restore', 'purge'], true) && $images->contains(fn (Image $image) => ! $image->trashed())) {
            throw ValidationException::withMessages(['ids' => '本次操作仅适用于回收站图片；尚未修改任何图片。']);
        }
        if (in_array($data['action'], ['copy', 'move', 'trash'], true) && $images->contains(fn (Image $image) => $image->trashed())) {
            throw ValidationException::withMessages(['ids' => '本次操作仅适用于图片库图片；尚未修改任何图片。']);
        }
        $completed = 0;
        $skipped = 0;
        $groupId = $request->filled('group_id') ? (int) $request->group_id : null;
        foreach ($images as $image) {
            try {
                if ($this->processBatchImage($image, $data['action'], $groupId, $storage)) {
                    $completed++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $exception) {
                report($exception);

                return back()->withErrors(['batch' => '已完成 '.$completed.' 张，跳过 '.$skipped.' 张；图片 #'.$image->id.' 处理失败，后续图片尚未处理。请刷新状态并检查运行日志后重试。']);
            }
        }

        return back()->with('success', '已处理 '.$completed.' 张图片。'.($skipped ? '另有 '.$skipped.' 张状态已变化，已跳过。' : ''));
    }

    private function processBatchImage(Image $image, string $action, ?int $groupId, ImageStorage $storage): bool
    {
        switch ($action) {
            case 'purge':
                return $storage->purge($image);
            case 'disable':
                $storage->disable($image);

                return true;
            case 'enable':
                $storage->enable($image);

                return true;
            case 'copy':
                $storage->duplicate($image, $groupId);

                return true;
            default:
                return $storage->withImage($image, fn () => DB::transaction(function () use ($image, $action, $groupId): bool {
                    $record = Image::withTrashed()->lockForUpdate()->find($image->id);
                    if (! $record || ($action === 'restore' ? ! $record->trashed() : $record->trashed())) {
                        return false;
                    }
                    match ($action) {
                        'trash' => $record->delete(),
                        'restore' => $record->restore(),
                        'move' => $record->update(['group_id' => $groupId]),
                    };

                    return true;
                }));
        }
    }

    public function preview(int $id, ImageStorage $storage): BinaryFileResponse
    {
        $image = Image::withTrashed()->findOrFail($id);
        $path = $storage->privatePath('previews', $image->preview_path);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function original(int $id, ImageStorage $storage): BinaryFileResponse
    {
        $image = Image::withTrashed()->findOrFail($id);
        abort_unless($image->original_path, 404);
        $path = $storage->privatePath('originals', $image->original_path);
        abort_unless(is_file($path), 404);
        $downloadName = preg_replace('/[\x00-\x1F\x7F]/u', '', $image->original_name) ?: 'original';

        return response()->download($path, $downloadName, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function group(Request $request): RedirectResponse
    {
        Group::create($request->validate(['name' => 'required|string|max:100|unique:groups,name']));

        return back()->with('success', '分组已创建。');
    }

    public function deleteGroup(Group $group): RedirectResponse
    {
        $group->delete();

        return back()->with('success', '分组已删除，图片已移至未分组。');
    }

    public function settings(): View
    {
        $all = Image::withTrashed();
        $stats = [
            'count' => (clone $all)->count(),
            'bytes' => (clone $all)->sum(DB::raw('bytes + preview_bytes')),
            'original_bytes' => (clone $all)->sum('original_bytes'),
            'trash_bytes' => Image::onlyTrashed()->sum(DB::raw('bytes + preview_bytes + original_bytes')),
            'free' => disk_free_space(base_path()), 'cron' => Setting::value('cron_last_run'),
        ];

        $storages = StorageConfiguration::withCount('images')->orderBy('id')->get();
        $storageStats = Image::withTrashed()->selectRaw('storage_configuration_id, SUM(bytes) AS webp_bytes, SUM(preview_bytes + original_bytes) AS private_bytes')->groupBy('storage_configuration_id')->get()->keyBy('storage_configuration_id');

        return view('settings', ['options' => Setting::uploadOptions(), 'groups' => Group::orderBy('name')->get(), 'stats' => $stats,
            'storages' => $storages, 'storageStats' => $storageStats, 'pendingOperations' => DB::table('storage_operations')->count(),
            'defaultStorageId' => Setting::value('default_storage_id') ?? $storages->firstWhere('driver', 'local')?->id]);
    }

    public function diagnostics(): View
    {
        $diagnostics = (new EnvironmentDiagnostics(base_path()))->run(true, false);

        return view('diagnostics', ['diagnostics' => $diagnostics]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'quality' => 'required|integer|min:1|max:100', 'lossless' => 'required|boolean',
            'max_edge' => 'required|integer|min:0|max:20000', 'keep_original' => 'required|boolean',
            'max_pixels' => 'required|integer|min:1000000|max:100000000',
            'max_file_mb' => 'required|integer|min:1|max:100', 'batch_limit' => 'required|integer|min:1|max:100',
        ]);
        Setting::put('upload', $data);

        return back()->with('success', '设置已保存，仅影响未来上传。');
    }

    public function cleanOriginals(Request $request, ImageStorage $storage): RedirectResponse
    {
        $request->validate(['confirmation' => 'required|in:清理所有历史原图']);
        $released = 0;
        $count = 0;
        $lastExistingId = Image::withTrashed()->max('id') ?? 0;
        try {
            Image::withTrashed()->where('id', '<=', $lastExistingId)->whereNotNull('original_path')->orderBy('id')->chunkById(50, function ($images) use ($storage, &$released, &$count): void {
                foreach ($images as $image) {
                    $released += $storage->deleteOriginal($image);
                    $count++;
                }
            });
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['originals' => '已清理 '.$count.' 份原图，释放 '.number_format($released / 1048576, 2).' MB；后续处理失败已停止，可检查日志后重试。']);
        }

        return back()->with('success', '已清理 '.$count.' 份历史原图，释放 '.number_format($released / 1048576, 2).' MB。WebP 和外链保持有效。');
    }
}
