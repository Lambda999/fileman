<?php

declare(strict_types=1);

$baseDir = __DIR__ . '/storage';
if (!is_dir($baseDir)) {
    mkdir($baseDir, 0775, true);
}

function normalizeRelativePath(string $input): string
{
    $input = trim(str_replace('\\', '/', $input));
    $input = trim($input, '/');

    if ($input === '') {
        return '';
    }

    $segments = explode('/', $input);
    $safe = [];

    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }

        $segment = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $segment);
        if ($segment === '' || $segment === '.' || $segment === '..') {
            continue;
        }

        $safe[] = $segment;
    }

    return implode('/', $safe);
}

function buildAbsolutePath(string $baseDir, string $relativePath): string
{
    $relativePath = normalizeRelativePath($relativePath);
    if ($relativePath === '') {
        return rtrim($baseDir, '/');
    }

    return rtrim($baseDir, '/') . '/' . $relativePath;
}

function ensureInsideBase(string $baseDir, string $path): bool
{
    $base = str_replace('\\', '/', realpath($baseDir) ?: $baseDir);
    $target = str_replace('\\', '/', realpath($path) ?: $path);

    $base = rtrim($base, '/');
    $target = rtrim($target, '/');

    return $target === $base || strpos($target, $base . '/') === 0;
}

function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$apiMode = isset($_GET['api']);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($apiMode && $action === 'download') {
    $relative = normalizeRelativePath((string) ($_GET['path'] ?? ''));
    $absolute = buildAbsolutePath($baseDir, $relative);

    if (!is_file($absolute) || !ensureInsideBase($baseDir, $absolute)) {
        http_response_code(404);
        echo 'File not found';
        exit;
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($absolute) . '"');
    header('Content-Length: ' . (string) filesize($absolute));
    readfile($absolute);
    exit;
}

if ($apiMode) {
    $relativePath = normalizeRelativePath((string) ($_GET['path'] ?? $_POST['path'] ?? ''));
    $currentDir = buildAbsolutePath($baseDir, $relativePath);

    if (!is_dir($currentDir) || !ensureInsideBase($baseDir, $currentDir)) {
        jsonResponse(['ok' => false, 'message' => '目录不存在或无权限。'], 400);
    }

    if ($action === 'list') {
        $scan = scandir($currentDir) ?: [];
        $items = [];

        foreach ($scan as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $absolute = $currentDir . '/' . $name;
            $itemRelative = ltrim($relativePath . '/' . $name, '/');
            $isDir = is_dir($absolute);
            $items[] = [
                'name' => $name,
                'path' => $itemRelative,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $isDir ? null : filesize($absolute),
                'mtime' => date('Y-m-d H:i:s', (int) filemtime($absolute)),
            ];
        }

        usort($items, static function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcmp((string) $a['name'], (string) $b['name']);
        });

        jsonResponse([
            'ok' => true,
            'data' => [
                'path' => $relativePath,
                'items' => $items,
            ],
        ]);
    }

    if ($action === 'mkdir' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $folderName = trim((string) ($_POST['name'] ?? ''));
        $folderName = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $folderName);

        if ($folderName === '') {
            jsonResponse(['ok' => false, 'message' => '文件夹名称不能为空。'], 400);
        }

        $folderPath = $currentDir . '/' . $folderName;
        if (!ensureInsideBase($baseDir, $folderPath)) {
            jsonResponse(['ok' => false, 'message' => '非法路径。'], 400);
        }
        if (is_dir($folderPath)) {
            jsonResponse(['ok' => false, 'message' => '文件夹已存在。'], 400);
        }

        if (!mkdir($folderPath, 0775, true)) {
            jsonResponse(['ok' => false, 'message' => '创建失败。'], 500);
        }

        jsonResponse(['ok' => true, 'message' => '文件夹创建成功。']);
    }

    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['ok' => false, 'message' => '上传失败，请检查文件。'], 400);
        }

        $original = (string) $_FILES['file']['name'];
        $safeName = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $original);
        if ($safeName === '') {
            jsonResponse(['ok' => false, 'message' => '文件名不合法。'], 400);
        }

        $target = $currentDir . '/' . $safeName;
        if (!ensureInsideBase($baseDir, $target)) {
            jsonResponse(['ok' => false, 'message' => '非法路径。'], 400);
        }

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $target)) {
            jsonResponse(['ok' => false, 'message' => '保存文件失败。'], 500);
        }

        jsonResponse(['ok' => true, 'message' => '上传成功。']);
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $itemPath = normalizeRelativePath((string) ($_POST['itemPath'] ?? ''));
        if ($itemPath === '') {
            jsonResponse(['ok' => false, 'message' => '目标不能为空。'], 400);
        }

        $absolute = buildAbsolutePath($baseDir, $itemPath);
        if (!file_exists($absolute) || !ensureInsideBase($baseDir, $absolute)) {
            jsonResponse(['ok' => false, 'message' => '文件或目录不存在。'], 404);
        }

        if (is_dir($absolute)) {
            $children = array_diff(scandir($absolute) ?: [], ['.', '..']);
            if (count($children) > 0) {
                jsonResponse(['ok' => false, 'message' => '只能删除空目录。'], 400);
            }

            if (!rmdir($absolute)) {
                jsonResponse(['ok' => false, 'message' => '删除目录失败。'], 500);
            }

            jsonResponse(['ok' => true, 'message' => '目录删除成功。']);
        }

        if (!unlink($absolute)) {
            jsonResponse(['ok' => false, 'message' => '删除文件失败。'], 500);
        }

        jsonResponse(['ok' => true, 'message' => '文件删除成功。']);
    }

    jsonResponse(['ok' => false, 'message' => '无效接口请求。'], 404);
}

?><!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>FileMan - Vue3 + Element Plus</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/element-plus/dist/index.css" />
  <style>
    body {
      margin: 0;
      background: #f5f7fa;
      font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    }
    .app-shell {
      max-width: 1200px;
      margin: 24px auto;
      padding: 0 16px;
    }
    .toolbar {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
      margin-bottom: 16px;
    }
    .path-tag {
      margin-right: 8px;
      margin-bottom: 8px;
      cursor: pointer;
    }
    .header-card {
      margin-bottom: 16px;
    }
  </style>
</head>
<body>
  <div id="app" class="app-shell">
    <el-card class="header-card" shadow="never">
      <template #header>
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:18px;font-weight:600;">📁 FileMan 文件管理</span>
          <el-tag type="info">Vue3 + Element Plus + PHP API</el-tag>
        </div>
      </template>

      <div style="margin-bottom: 10px; color:#606266;">当前路径：<span>{{ currentPath ? '/' + currentPath : '/' }}</span></div>
      <div>
        <el-tag class="path-tag" @click="goRoot">root</el-tag>
        <el-tag
          v-for="(seg, idx) in pathSegments"
          :key="idx"
          class="path-tag"
          type="success"
          @click="goToSegment(idx)"
        >{{ seg }}</el-tag>
      </div>
    </el-card>

    <div class="toolbar">
      <el-button type="primary" @click="openMkdir">新建文件夹</el-button>
      <el-upload
        :auto-upload="false"
        :show-file-list="false"
        :on-change="onFileChange"
      >
        <el-button type="success">选择文件上传</el-button>
      </el-upload>
      <el-button @click="fetchList" :loading="loading">刷新</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="items" v-loading="loading" stripe>
        <el-table-column prop="name" label="名称" min-width="240">
          <template #default="scope">
            <el-icon style="margin-right:6px;vertical-align:middle;">
              <folder v-if="scope.row.type==='dir'"></folder>
              <document v-if="scope.row.type==='file'"></document>
            </el-icon>
            <span>{{ scope.row.name }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="type" label="类型" width="120" />
        <el-table-column prop="mtime" label="修改时间" width="190" />
        <el-table-column label="大小" width="120">
          <template #default="scope">
            {{ scope.row.type === 'dir' ? '-' : scope.row.size }}
          </template>
        </el-table-column>
        <el-table-column label="操作" width="260" fixed="right">
          <template #default="scope">
            <el-button v-if="scope.row.type==='dir'" link type="primary" @click="enter(scope.row)">打开</el-button>
            <el-button v-if="scope.row.type==='file'" link type="success" @click="download(scope.row)">下载</el-button>
            <el-button link type="danger" @click="remove(scope.row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="mkdirVisible" title="新建文件夹" width="400px">
      <el-input v-model="newFolderName" placeholder="请输入文件夹名称" />
      <template #footer>
        <el-button @click="mkdirVisible=false">取消</el-button>
        <el-button type="primary" @click="createFolder">创建</el-button>
      </template>
    </el-dialog>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/element-plus"></script>
  <script src="https://cdn.jsdelivr.net/npm/@element-plus/icons-vue"></script>
  <script>
    const { createApp, ref, computed, onMounted } = Vue;
    const { ElMessage, ElMessageBox } = ElementPlus;

    createApp({
      setup() {
        const loading = ref(false);
        const currentPath = ref('');
        const items = ref([]);
        const mkdirVisible = ref(false);
        const newFolderName = ref('');

        const pathSegments = computed(() => currentPath.value ? currentPath.value.split('/') : []);

        const fetchList = async () => {
          loading.value = true;
          try {
            const res = await fetch(`?api=1&action=list&path=${encodeURIComponent(currentPath.value)}`);
            const json = await res.json();
            if (!json.ok) {
              throw new Error(json.message || '加载失败');
            }
            items.value = json.data.items || [];
            currentPath.value = json.data.path || '';
          } catch (e) {
            ElMessage.error(e.message || '加载失败');
          } finally {
            loading.value = false;
          }
        };

        const openMkdir = () => {
          newFolderName.value = '';
          mkdirVisible.value = true;
        };

        const createFolder = async () => {
          if (!newFolderName.value.trim()) {
            ElMessage.warning('请输入文件夹名称');
            return;
          }

          const form = new FormData();
          form.append('name', newFolderName.value.trim());
          form.append('path', currentPath.value);

          const res = await fetch('?api=1&action=mkdir', { method: 'POST', body: form });
          const json = await res.json();
          if (!json.ok) {
            ElMessage.error(json.message || '创建失败');
            return;
          }

          ElMessage.success(json.message || '创建成功');
          mkdirVisible.value = false;
          fetchList();
        };

        const onFileChange = async (file) => {
          const form = new FormData();
          form.append('file', file.raw);
          form.append('path', currentPath.value);

          const res = await fetch('?api=1&action=upload', { method: 'POST', body: form });
          const json = await res.json();
          if (!json.ok) {
            ElMessage.error(json.message || '上传失败');
            return;
          }

          ElMessage.success(json.message || '上传成功');
          fetchList();
        };

        const enter = (row) => {
          currentPath.value = row.path;
          fetchList();
        };

        const goRoot = () => {
          currentPath.value = '';
          fetchList();
        };

        const goToSegment = (idx) => {
          currentPath.value = pathSegments.value.slice(0, idx + 1).join('/');
          fetchList();
        };

        const remove = async (row) => {
          try {
            await ElMessageBox.confirm(`确认删除 ${row.name} 吗？`, '提示', { type: 'warning' });
          } catch (_) {
            return;
          }

          const form = new FormData();
          form.append('itemPath', row.path);

          const res = await fetch('?api=1&action=delete', { method: 'POST', body: form });
          const json = await res.json();
          if (!json.ok) {
            ElMessage.error(json.message || '删除失败');
            return;
          }

          ElMessage.success(json.message || '删除成功');
          fetchList();
        };

        const download = (row) => {
          window.open(`?api=1&action=download&path=${encodeURIComponent(row.path)}`, '_blank');
        };

        onMounted(fetchList);

        return {
          loading,
          currentPath,
          items,
          mkdirVisible,
          newFolderName,
          pathSegments,
          fetchList,
          openMkdir,
          createFolder,
          onFileChange,
          enter,
          goRoot,
          goToSegment,
          remove,
          download,
        };
      }
    })
    .component('Folder', ElementPlusIconsVue.Folder)
    .component('Document', ElementPlusIconsVue.Document)
    .use(ElementPlus)
    .mount('#app');
  </script>
</body>
</html>
