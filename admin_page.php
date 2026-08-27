<?php
require_once dirname(dirname(__DIR__)) . '/admin/auth_check.php';
require_once dirname(dirname(__DIR__)) . '/admin/layout.php';

$baseUploadDir = realpath(UPLOADS_PATH);
if (!$baseUploadDir || !is_dir($baseUploadDir)) {
    mkdir(UPLOADS_PATH, 0775, true);
    $baseUploadDir = realpath(UPLOADS_PATH);
}

// ----------------------------------------------------
// 1. RESOLVE & SANITIZE CURRENT DIRECTORY
// ----------------------------------------------------
$currentSubDir = trim($_GET['dir'] ?? '', '/\\');
$currentSubDir = str_replace(['..', "\0"], '', $currentSubDir); // Prevent directory traversal

$currentFullPath = $baseUploadDir . ($currentSubDir ? '/' . $currentSubDir : '');
if (!is_dir($currentFullPath) || strpos(realpath($currentFullPath), $baseUploadDir) !== 0) {
    $currentSubDir = '';
    $currentFullPath = $baseUploadDir;
}

$currentUrl = BASE_URL . '/uploads' . ($currentSubDir ? '/' . $currentSubDir : '');

// ----------------------------------------------------
// 2. ACTION: CREATE SUBFOLDER
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder'])) {
    $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($_POST['folder_name'] ?? ''));
    if (!empty($folderName)) {
        $targetFolder = $currentFullPath . '/' . $folderName;
        if (!file_exists($targetFolder)) {
            mkdir($targetFolder, 0775, true);
            set_flash('success', "Folder '{$folderName}' created.");
        } else {
            set_flash('error', "A folder or file named '{$folderName}' already exists.");
        }
    }
    redirect('admin_page.php?dir=' . urlencode($currentSubDir));
}

// ----------------------------------------------------
// 3. ACTION: UPLOAD FILES INTO CURRENT DIRECTORY
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['uploaded_files'])) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'zip', 'txt', 'mp3', 'mp4', 'svg', 'json'];
    $files = $_FILES['uploaded_files'];
    $count = count($files['name']);
    $uploadedCount = 0;

    for ($i = 0; $i < $count; $i++) {
        if ($files['error'][$i] === UPLOAD_ERR_OK) {
            $originalName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $files['name'][$i]);
            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (in_array($ext, $allowed)) {
                $targetFile = $currentFullPath . '/' . time() . '_' . $originalName;
                if (move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
                    $uploadedCount++;
                }
            }
        }
    }

    if ($uploadedCount > 0) {
        set_flash('success', "Uploaded {$uploadedCount} file(s) to " . ($currentSubDir ? "/{$currentSubDir}" : "root uploads") . ".");
    } else {
        set_flash('error', "No valid files uploaded. Allowed: " . implode(', ', $allowed));
    }
    redirect('admin_page.php?dir=' . urlencode($currentSubDir));
}

// ----------------------------------------------------
// 4. ACTION: RENAME ITEM
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_item'])) {
    $oldName = basename($_POST['old_name'] ?? '');
    $newName = preg_replace('/[^a-zA-Z0-9._-]/', '_', trim($_POST['new_name'] ?? ''));

    if (!empty($oldName) && !empty($newName)) {
        $oldPath = $currentFullPath . '/' . $oldName;
        $newPath = $currentFullPath . '/' . $newName;

        if (file_exists($oldPath) && !file_exists($newPath)) {
            rename($oldPath, $newPath);
            set_flash('success', "Renamed '{$oldName}' to '{$newName}'.");
        } else {
            set_flash('error', "Cannot rename. Target name already exists or file missing.");
        }
    }
    redirect('admin_page.php?dir=' . urlencode($currentSubDir));
}

// ----------------------------------------------------
// 5. ACTION: DELETE FILE OR FOLDER
// ----------------------------------------------------
if (isset($_GET['delete'])) {
    $itemToDelete = basename($_GET['delete']);
    $fullPath = $currentFullPath . '/' . $itemToDelete;

    if ($itemToDelete === 'thumbs' && $currentSubDir === '') {
        set_flash('error', "System folder 'thumbs' cannot be deleted.");
    } elseif (is_dir($fullPath)) {
        // Recursive folder delete
        $deleteFolder = function($dir) use (&$deleteFolder) {
            foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
                $p = $dir . '/' . $file;
                is_dir($p) ? $deleteFolder($p) : @unlink($p);
            }
            return @rmdir($dir);
        };
        $deleteFolder($fullPath);
        set_flash('success', "Folder '{$itemToDelete}' and all its contents were deleted.");
    } elseif (is_file($fullPath)) {
        @unlink($fullPath);
        set_flash('success', "File '{$itemToDelete}' deleted.");
    }
    redirect('admin_page.php?dir=' . urlencode($currentSubDir));
}

// ----------------------------------------------------
// 6. AUTOINDEX DIRECTORY SCANNING
// ----------------------------------------------------
$rawItems = array_diff(scandir($currentFullPath), ['.', '..']);
$folders  = [];
$files    = [];

foreach ($rawItems as $item) {
    $path = $currentFullPath . '/' . $item;
    if (is_dir($path)) {
        if ($item === 'thumbs' && $currentSubDir === '') continue; // Skip cache folder in root
        $itemCount = count(array_diff(scandir($path), ['.', '..']));
        $folders[] = [
            'name'       => $item,
            'sub_path'   => $currentSubDir ? $currentSubDir . '/' . $item : $item,
            'item_count' => $itemCount,
            'updated_at' => date('Y-m-d H:i', filemtime($path))
        ];
    } elseif (is_file($path)) {
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
        $fileUrl = $currentUrl . '/' . $item;
        
        $imgDimensions = null;
        if ($isImg && $ext !== 'svg') {
            $info = @getimagesize($path);
            if ($info) $imgDimensions = $info[0] . '×' . $info[1];
        }

        $files[] = [
            'name'       => $item,
            'ext'        => $ext,
            'is_img'     => $isImg,
            'size'       => round(filesize($path) / 1024, 1) . ' KB',
            'dimensions' => $imgDimensions,
            'url'        => $fileUrl,
            'updated_at' => date('Y-m-d H:i', filemtime($path))
        ];
    }
}

// Sort folders alphabetically, files newest first
usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
usort($files, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

// Build Breadcrumbs
$breadcrumbs = [];
$breadcrumbs[] = ['name' => '📁 uploads', 'dir' => ''];
if ($currentSubDir) {
    $parts = explode('/', $currentSubDir);
    $accum = '';
    foreach ($parts as $part) {
        $accum .= ($accum ? '/' : '') . $part;
        $breadcrumbs[] = ['name' => $part, 'dir' => $accum];
    }
}

admin_header('File Autoindex', 'file_manager');
?>

<style>
.autoindex-bar { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 20px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px; flex-wrap: wrap; gap: 12px; }
.breadcrumbs { display: flex; align-items: center; gap: 8px; font-size: 0.95rem; font-weight: 600; flex-wrap: wrap; }
.breadcrumbs a { color: #0284c7; text-decoration: none; }
.breadcrumbs a:hover { text-decoration: underline; }
.breadcrumbs span { color: #94a3b8; }

.index-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 16px; margin-top: 15px; }

/* Folder Card */
.folder-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; display: flex; align-items: center; justify-content: space-between; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
.folder-card:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.06); border-color: #cbd5e1; }
.folder-link { display: flex; align-items: center; gap: 10px; text-decoration: none; color: #1e293b; font-weight: 600; font-size: 0.9rem; overflow: hidden; }
.folder-link span.fname { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* File Card */
.file-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; box-shadow: 0 1px 3px rgba(0,0,0,0.03); }
.file-preview { height: 130px; background: #f8fafc; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.file-preview img { width: 100%; height: 100%; object-fit: cover; }
.file-details { padding: 12px; }
.file-name { font-size: 0.82rem; font-weight: 600; color: #1e293b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.file-meta { font-size: 0.72rem; color: #64748b; margin-top: 3px; display: flex; justify-content: space-between; }

.search-filter-box { max-width: 250px; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.88rem; outline: none; }
</style>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px; flex-wrap:wrap; gap:10px;">
    <h2>📁 Media Autoindex (v1.2)</h2>
    <input type="text" id="liveSearch" class="search-filter-box" placeholder="🔍 Filter files/folders..." onkeyup="filterItems()">
</div>

<!-- Navigation Breadcrumbs & Top Actions -->
<div class="autoindex-bar">
    <div class="breadcrumbs">
        <?php foreach ($breadcrumbs as $i => $b): ?>
            <?php if ($i === count($breadcrumbs) - 1): ?>
                <strong><?php echo htmlspecialchars($b['name']); ?></strong>
            <?php else: ?>
                <a href="admin_page.php?dir=<?php echo urlencode($b['dir']); ?>"><?php echo htmlspecialchars($b['name']); ?></a>
                <span>/</span>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Actions -->
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <!-- New Folder Trigger -->
        <button type="button" class="btn btn-sm" onclick="promptNewFolder()" style="background:#e2e8f0; color:#334155;">➕ New Folder</button>
        <!-- Upload Form Trigger -->
        <label class="btn btn-sm btn-primary" style="cursor:pointer; margin:0;">
            📤 Upload Files
            <input type="file" name="uploaded_files[]" id="fileUploadInput" multiple onchange="document.getElementById('autoUploadForm').submit();" style="display:none;">
        </label>
    </div>
</div>

<!-- Hidden Auto-Upload Form -->
<form id="autoUploadForm" method="POST" enctype="multipart/form-data" style="display:none;">
    <input type="file" name="uploaded_files[]" id="mirrorUploadInput" multiple>
</form>

<!-- Hidden New Folder Form -->
<form id="newFolderForm" method="POST" style="display:none;">
    <input type="hidden" name="create_folder" value="1">
    <input type="hidden" name="folder_name" id="folderNameInput">
</form>

<!-- Hidden Rename Form -->
<form id="renameForm" method="POST" style="display:none;">
    <input type="hidden" name="rename_item" value="1">
    <input type="hidden" name="old_name" id="renameOldName">
    <input type="hidden" name="new_name" id="renameNewName">
</form>

<!-- ====================================================
     FOLDERS SECTION
     ==================================================== -->
<?php if (!empty($folders) || $currentSubDir !== ''): ?>
    <div class="panel" style="margin-bottom: 20px;">
        <h3 class="panel-title" style="font-size: 0.95rem; text-transform:uppercase; color:#64748b;">Directories</h3>
        <div class="index-grid">
            <!-- Back to Parent Directory Card -->
            <?php if ($currentSubDir !== ''): 
                $parentParts = explode('/', $currentSubDir);
                array_pop($parentParts);
                $parentDir = implode('/', $parentParts);
            ?>
                <div class="folder-card" style="background:#f8fafc;">
                    <a href="admin_page.php?dir=<?php echo urlencode($parentDir); ?>" class="folder-link" style="color:#0284c7;">
                        <span style="font-size:1.4rem;">🔙</span>
                        <span class="fname">.. (Parent Folder)</span>
                    </a>
                </div>
            <?php endif; ?>

            <!-- Subfolders -->
            <?php foreach ($folders as $f): ?>
                <div class="folder-card index-item" data-name="<?php echo htmlspecialchars(strtolower($f['name'])); ?>">
                    <a href="admin_page.php?dir=<?php echo urlencode($f['sub_path']); ?>" class="folder-link">
                        <span style="font-size:1.5rem;">📂</span>
                        <div>
                            <div class="fname" title="<?php echo htmlspecialchars($f['name']); ?>"><?php echo htmlspecialchars($f['name']); ?></div>
                            <div style="font-size:0.72rem; color:#94a3b8; font-weight:normal;"><?php echo $f['item_count']; ?> item(s)</div>
                        </div>
                    </a>
                    <div style="display:flex; gap:4px;">
                        <button type="button" class="btn btn-sm" onclick="promptRename('<?php echo htmlspecialchars(addslashes($f['name'])); ?>')" style="padding:2px 6px; font-size:0.7rem; background:#f1f5f9; color:#475569;" title="Rename">✏️</button>
                        <a href="admin_page.php?dir=<?php echo urlencode($currentSubDir); ?>&delete=<?php echo urlencode($f['name']); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete folder <?php echo htmlspecialchars(addslashes($f['name'])); ?> and all its files?');" style="padding:2px 6px; font-size:0.7rem;" title="Delete">🗑️</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- ====================================================
     FILES SECTION
     ==================================================== -->
<div class="panel">
    <h3 class="panel-title" style="font-size: 0.95rem; text-transform:uppercase; color:#64748b;">
        Files (<?php echo count($files); ?>)
    </h3>

    <?php if (!empty($files)): ?>
        <div class="index-grid">
            <?php foreach ($files as $file): ?>
                <div class="file-card index-item" data-name="<?php echo htmlspecialchars(strtolower($file['name'])); ?>">
                    <!-- Preview -->
                    <div class="file-preview">
                        <?php if ($file['is_img']): ?>
                            <img src="<?php echo $file['url']; ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <div style="font-size: 2.5rem; color:#64748b;">
                                <?php 
                                    if ($file['ext'] === 'pdf') echo '📕';
                                    elseif (in_array($file['ext'], ['zip', 'rar', 'tar', 'gz'])) echo '📦';
                                    elseif (in_array($file['ext'], ['mp3', 'wav', 'ogg'])) echo '🎵';
                                    elseif (in_array($file['ext'], ['mp4', 'mov', 'webm'])) echo '🎬';
                                    else echo '📄';
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Details -->
                    <div class="file-details">
                        <div class="file-name" title="<?php echo htmlspecialchars($file['name']); ?>">
                            <?php echo htmlspecialchars($file['name']); ?>
                        </div>
                        <div class="file-meta">
                            <span><?php echo $file['size']; ?></span>
                            <?php if ($file['dimensions']): ?>
                                <span><?php echo $file['dimensions']; ?></span>
                            <?php else: ?>
                                <code>.<?php echo $file['ext']; ?></code>
                            <?php endif; ?>
                        </div>

                        <!-- Actions -->
                        <div style="display:flex; gap:4px; margin-top:10px;">
                            <button type="button" class="btn btn-sm btn-primary" style="flex:1; font-size:0.75rem; padding:4px 6px;" onclick="copyFileUrl('<?php echo $file['url']; ?>')">
                                📋 Copy URL
                            </button>
                            <button type="button" class="btn btn-sm" onclick="promptRename('<?php echo htmlspecialchars(addslashes($file['name'])); ?>')" style="padding:4px 6px; font-size:0.75rem; background:#f1f5f9; color:#475569;" title="Rename">
                                ✏️
                            </button>
                            <a href="admin_page.php?dir=<?php echo urlencode($currentSubDir); ?>&delete=<?php echo urlencode($file['name']); ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete <?php echo htmlspecialchars(addslashes($file['name'])); ?>?');" style="padding:4px 6px; font-size:0.75rem;" title="Delete">
                                🗑️
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div style="padding: 30px; text-align: center; color: #64748b;">
            This folder is empty. Click <strong>Upload Files</strong> or drag & drop files here!
        </div>
    <?php endif; ?>
</div>

<!-- Autoindex JavaScript Functions -->
<script>
// 1. New Folder Prompt
function promptNewFolder() {
    const name = prompt("Enter new folder name:");
    if (name && name.trim()) {
        document.getElementById('folderNameInput').value = name.trim();
        document.getElementById('newFolderForm').submit();
    }
}

// 2. Rename Prompt
function promptRename(oldName) {
    const newName = prompt("Rename to:", oldName);
    if (newName && newName.trim() && newName.trim() !== oldName) {
        document.getElementById('renameOldName').value = oldName;
        document.getElementById('renameNewName').value = newName.trim();
        document.getElementById('renameForm').submit();
    }
}

// 3. Fast Copy URL with Visual Feedback
function copyFileUrl(url) {
    navigator.clipboard.writeText(url).then(() => {
        alert("Copied direct URL:\n\n" + url);
    });
}

// 4. Live Search Filter
function filterItems() {
    const q = document.getElementById('liveSearch').value.toLowerCase();
    const items = document.querySelectorAll('.index-item');
    items.forEach(el => {
        const name = el.getAttribute('data-name') || '';
        el.style.display = name.includes(q) ? '' : 'none';
    });
}

// 5. Connect File Input to Form
document.getElementById('fileUploadInput').addEventListener('change', function() {
    const mirror = document.getElementById('mirrorUploadInput');
    mirror.files = this.files;
    document.getElementById('autoUploadForm').submit();
});
</script>

<?php admin_footer(); ?>