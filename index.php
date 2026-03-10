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
        if ($segment === null || $segment === '' || $segment === '.' || $segment === '..') {
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

function buildTree(string $absoluteDir, string $relativePath = ''): array
{
    $nodes = [];
    $items = scandir($absoluteDir) ?: [];

    foreach ($items as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }

        $itemAbsolute = rtrim($absoluteDir, '/') . '/' . $name;
        if (!is_dir($itemAbsolute)) {
            continue;
        }

        $itemRelative = ltrim($relativePath . '/' . $name, '/');
        $nodes[] = [
            'label' => $name,
            'path' => $itemRelative,
            'children' => buildTree($itemAbsolute, $itemRelative),
        ];
    }

    usort($nodes, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

    return $nodes;
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

    if ($action === 'tree') {
        jsonResponse([
            'ok' => true,
            'data' => [
                'path' => $relativePath,
                'tree' => [[
                    'label' => 'root',
                    'path' => '',
                    'children' => buildTree($baseDir, ''),
                ]],
            ],
        ]);
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

        if ($folderName === null || $folderName === '') {
            jsonResponse(['ok' => false, 'message' => '文件夹名称不能为空。'], 400);
        }

        $folderPath = $currentDir . '/' . $folderName;
        if (!ensureInsideBase($baseDir, $folderPath)) {
            jsonResponse(['ok' => false, 'message' => '非法路径。'], 400);
        }
        if (file_exists($folderPath)) {
            jsonResponse(['ok' => false, 'message' => '文件夹已存在。'], 400);
        }

        if (!mkdir($folderPath, 0775, true)) {
            jsonResponse(['ok' => false, 'message' => '创建失败，请检查目录权限。'], 500);
        }

        jsonResponse(['ok' => true, 'message' => '文件夹创建成功。']);
    }

    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['ok' => false, 'message' => '上传失败，请检查文件。'], 400);
        }

        $original = (string) $_FILES['file']['name'];
        $safeName = preg_replace('/[^\p{L}\p{N}\-_. ]/u', '_', $original);
        if ($safeName === null || $safeName === '') {
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
  <title>FileMan - Modern Edition</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/element-plus/dist/index.css" />
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: radial-gradient(circle at 15% 20%, #eef6ff 0%, #f8faff 40%, #f4f7ff 100%);
      color: #1f2937;
    }
    .app-shell {
      max-width: 1380px;
      margin: 0 auto;
      padding: 18px;
    }
    .hero {
      border-radius: 18px;
      background: linear-gradient(120deg, #4f46e5 0%, #2563eb 45%, #06b6d4 100%);
      color: #fff;
      padding: 20px 24px;
      box-shadow: 0 12px 30px rgba(37, 99, 235, 0.28);
      margin-bottom: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
    }
    .hero h2 { margin: 0 0 6px; font-size: 24px; }
    .hero p { margin: 0; opacity: 0.92; }
    .layout {
      display: grid;
      grid-template-columns: 320px minmax(0, 1fr);
      gap: 16px;
    }
    .panel {
      background: rgba(255,255,255,.86);
      backdrop-filter: blur(10px);
      border-radius: 16px;
      border: 1px solid rgba(255,255,255,.6);
      box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
      overflow: hidden;
    }
    .panel-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 14px 16px;
      border-bottom: 1px solid #edf2ff;
    }
    .panel-body { padding: 12px 16px 16px; }
    .toolbar { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
    .path-chip { margin-right: 8px; cursor: pointer; }
    .tree-wrap { max-height: 560px; overflow: auto; }
    .tree-node {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      font-size: 13px;
    }
    @media (max-width: 980px) {
      .layout { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
<div id="app" class="app-shell">
  <section class="hero">
    <div>
      <h2>✨ FileMan Modern</h2>
      <p>现代化文件管理 · 目录树 · 单文件 PHP API</p>
    </div>
    <el-tag effect="dark" type="success">Vue3 + Element Plus</el-tag>
  </section>

  <section class="layout">
    <div class="panel">
      <div class="panel-head">
        <strong>目录树</strong>
        <el-button size="small" @click="refreshTree">刷新</el-button>
      </div>
      <div class="panel-body tree-wrap" v-loading="treeLoading">
        <el-tree
          :data="treeData"
          node-key="path"
          default-expand-all
          :expand-on-click-node="false"
          @node-click="onTreeClick"
        >
          <template #default="{ data }">
            <span class="tree-node">
              <el-icon><folder></folder></el-icon>
              <span>{{ data.label }}</span>
            </span>
          </template>
        </el-tree>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <strong>文件列表</strong>
        <div>
          <el-tag class="path-chip" @click="goRoot">root</el-tag>
          <el-tag class="path-chip" v-for="(seg, idx) in pathSegments" :key="idx" type="info" @click="goToSegment(idx)">{{ seg }}</el-tag>
        </div>
      </div>
      <div class="panel-body">
        <div style="margin-bottom:10px;color:#667085;">当前路径：{{ currentPath ? '/' + currentPath : '/' }}</div>

        <div class="toolbar">
          <el-button type="primary" @click="openMkdir">新建目录</el-button>
          <el-upload :auto-upload="false" :show-file-list="false" :on-change="onFileChange">
            <el-button type="success">上传文件</el-button>
          </el-upload>
          <el-button @click="fetchList" :loading="loading">刷新列表</el-button>
        </div>

        <el-table :data="items" v-loading="loading" stripe>
          <el-table-column prop="name" label="名称" min-width="240">
            <template #default="scope">
              <el-icon style="margin-right:6px;vertical-align:middle;">
                <folder v-if="scope.row.type==='dir'"></folder>
                <document v-if="scope.row.type==='file'"></document>
              </el-icon>
              <span v-if="scope.row.type==='dir'" style="color:#2563eb;cursor:pointer;font-weight:600;" @click.stop="enter(scope.row)">{{ scope.row.name }}</span>
              <span v-if="scope.row.type==='file'">{{ scope.row.name }}</span>
            </template>
          </el-table-column>
          <el-table-column prop="type" label="类型" width="110"></el-table-column>
          <el-table-column prop="mtime" label="修改时间" width="180"></el-table-column>
          <el-table-column label="大小" width="110">
            <template #default="scope">{{ scope.row.type === 'dir' ? '-' : scope.row.size }}</template>
          </el-table-column>
          <el-table-column label="操作" width="260" fixed="right">
            <template #default="scope">
              <el-button v-if="scope.row.type==='dir'" link type="primary" @click="enter(scope.row)">打开</el-button>
              <el-button v-if="scope.row.type==='file'" link type="success" @click="download(scope.row)">下载</el-button>
              <el-button link type="danger" @click="remove(scope.row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>
  </section>

  <el-dialog v-model="mkdirVisible" title="新建目录" width="420px">
    <el-input v-model="newFolderName" placeholder="请输入目录名称，如 docs"></el-input>
    <div style="margin-top:8px;color:#909399;">将在 {{ currentPath ? '/' + currentPath : '/' }} 下创建</div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:16px;">
      <el-button @click="mkdirVisible=false">取消</el-button>
      <el-button type="primary" :loading="mkdirLoading" @click="createFolder">确认创建</el-button>
    </div>
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
      const treeLoading = ref(false);
      const mkdirLoading = ref(false);
      const currentPath = ref('');
      const items = ref([]);
      const treeData = ref([]);
      const mkdirVisible = ref(false);
      const newFolderName = ref('');

      const pathSegments = computed(() => currentPath.value ? currentPath.value.split('/') : []);

      const apiFetch = async (url, options) => {
        const res = await fetch(url, options || {});
        const json = await res.json();
        if (!json.ok) {
          throw new Error(json.message || '请求失败');
        }
        return json;
      };

      const fetchList = async () => {
        loading.value = true;
        try {
          const json = await apiFetch(`?api=1&action=list&path=${encodeURIComponent(currentPath.value)}`);
          items.value = json.data.items || [];
          currentPath.value = json.data.path || '';
        } catch (e) {
          ElMessage.error(e.message || '加载失败');
        } finally {
          loading.value = false;
        }
      };

      const refreshTree = async () => {
        treeLoading.value = true;
        try {
          const json = await apiFetch('?api=1&action=tree');
          treeData.value = json.data.tree || [];
        } catch (e) {
          ElMessage.error(e.message || '目录树加载失败');
        } finally {
          treeLoading.value = false;
        }
      };

      const openMkdir = () => {
        newFolderName.value = '';
        mkdirVisible.value = true;
      };

      const createFolder = async () => {
        const folder = newFolderName.value.trim();
        if (!folder) {
          ElMessage.warning('请输入目录名称');
          return;
        }

        mkdirLoading.value = true;
        try {
          const form = new FormData();
          form.append('name', folder);
          form.append('path', currentPath.value);
          const json = await apiFetch('?api=1&action=mkdir', { method: 'POST', body: form });
          ElMessage.success(json.message || '创建成功');
          mkdirVisible.value = false;
          await Promise.all([fetchList(), refreshTree()]);
        } catch (e) {
          ElMessage.error(e.message || '创建失败');
        } finally {
          mkdirLoading.value = false;
        }
      };

      const onFileChange = async (file) => {
        try {
          const form = new FormData();
          form.append('file', file.raw);
          form.append('path', currentPath.value);
          const json = await apiFetch('?api=1&action=upload', { method: 'POST', body: form });
          ElMessage.success(json.message || '上传成功');
          await Promise.all([fetchList(), refreshTree()]);
        } catch (e) {
          ElMessage.error(e.message || '上传失败');
        }
      };

      const enter = async (row) => {
        currentPath.value = row.path;
        await fetchList();
      };




      const onTreeClick = async (node) => {
        currentPath.value = node.path || '';
        await fetchList();
      };

      const goRoot = async () => {
        currentPath.value = '';
        await fetchList();
      };

      const goToSegment = async (idx) => {
        currentPath.value = pathSegments.value.slice(0, idx + 1).join('/');
        await fetchList();
      };

      const remove = async (row) => {
        try {
          await ElMessageBox.confirm(`确认删除 ${row.name} 吗？`, '提示', { type: 'warning' });
        } catch (_) {
          return;
        }

        try {
          const form = new FormData();
          form.append('itemPath', row.path);
          const json = await apiFetch('?api=1&action=delete', { method: 'POST', body: form });
          ElMessage.success(json.message || '删除成功');
          await Promise.all([fetchList(), refreshTree()]);
        } catch (e) {
          ElMessage.error(e.message || '删除失败');
        }
      };

      const download = (row) => {
        window.open(`?api=1&action=download&path=${encodeURIComponent(row.path)}`, '_blank');
      };

      onMounted(async () => {
        await Promise.all([fetchList(), refreshTree()]);
      });

      return {
        loading,
        treeLoading,
        mkdirLoading,
        currentPath,
        items,
        treeData,
        mkdirVisible,
        newFolderName,
        pathSegments,
        fetchList,
        refreshTree,
        openMkdir,
        createFolder,
        onFileChange,
        enter,
        onTreeClick,
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
