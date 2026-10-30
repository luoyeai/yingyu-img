<?php

return [
    'required' => ':attribute不能为空。', 'email' => '请输入有效的:attribute。', 'string' => ':attribute必须是文本。', 'integer' => ':attribute必须是整数。', 'boolean' => ':attribute选项无效。', 'array' => ':attribute必须是列表。', 'file' => ':attribute必须是有效上传文件。', 'uploaded' => ':attribute上传失败，请检查文件大小和服务器限制。',
    'confirmed' => '两次输入的:attribute不一致。', 'current_password' => '当前密码不正确。', 'unique' => ':attribute已存在。', 'exists' => ':attribute不存在，请刷新后重试。', 'in' => ':attribute选项或确认文字不正确。', 'distinct' => '所选:attribute重复。', 'regex' => ':attribute格式不正确。', 'date_format' => ':attribute格式应为 :format。',
    'min' => ['numeric' => ':attribute不能小于 :min。', 'string' => ':attribute至少需要 :min 个字符。', 'array' => ':attribute至少需要选择 :min 项。', 'file' => ':attribute至少需要 :min KB。'],
    'max' => ['numeric' => ':attribute不能大于 :max。', 'string' => ':attribute不能超过 :max 个字符。', 'array' => ':attribute不能超过 :max 项。', 'file' => ':attribute不能超过 :max KB。'],
    'attributes' => ['email' => '邮箱', 'password' => '密码', 'current_password' => '当前密码', 'file' => '图片', 'ids' => '图片', 'ids.*' => '图片', 'group_id' => '分组', 'name' => '分组名称', 'display_name' => '展示名称', 'alt' => '替代文本', 'confirmation' => '确认文字', 'quality' => '输出质量', 'max_edge' => '最长边', 'max_pixels' => '像素上限', 'max_file_mb' => '单图大小上限', 'batch_limit' => '批量数量', 'date' => '日期', 'q' => '搜索内容', 'group' => '分组', 'preset' => '预设', 'action' => '操作', 'lossless' => '无损压缩', 'keep_original' => '保留原图'],
];
