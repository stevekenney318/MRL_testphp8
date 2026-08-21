<?php
declare(strict_types=1);

/**
 * mrl_team_user_menu_native_fix_patch.php
 * VERSION: v002
 * LAST MODIFIED: 8/20/2026 8:29:00 pm
 *
 * TestPHP8-only follow-up patch.
 * Installs team.php v023.
 * Replaces the v022 deferred menu listener with a direct native onclick toggle.
 * No database changes. PHP 7.3 compatible.
 */

session_start();
date_default_timezone_set('America/New_York');

$expectedHost = 'testphp8.manliusracingleague.com';
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
$teamPath = $root . '/team.php';
$checks = [];
$errors = [];
$postflight = [];
$installed = false;
$backupDir = '';

function ih($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function add_check(array &$checks, string $name, bool $ok, string $detail=''): void { $checks[]=['name'=>$name,'ok'=>$ok,'detail'=>$detail]; }
function replace_once(string $src, string $old, string $new, string $label): string {
    $count = substr_count($src, $old);
    if ($count !== 1) throw new RuntimeException($label . ': expected exactly 1 match, found ' . $count . '.');
    return str_replace($old, $new, $src);
}
function atomic_write(string $path, string $content): void {
    $tmp = $path . '.mrl_tmp_' . uniqid('', true);
    if (@file_put_contents($tmp, $content, LOCK_EX) === false) throw new RuntimeException('Unable to write temporary team.php.');
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Unable to replace team.php.'); }
}

add_check($checks, 'Host is TESTPHP8', $host === $expectedHost, $host ?: '(unknown)');
add_check($checks, 'Document root available', $root !== '' && is_dir($root), $root);
add_check($checks, 'team.php exists', is_file($teamPath), $teamPath);
if ($host !== $expectedHost) $errors[] = 'REFUSED: this patch is TestPHP8-only.';
if ($root === '' || !is_dir($root)) $errors[] = 'Document root unavailable.';
if (!is_file($teamPath)) $errors[] = 'team.php not found.';

$prepared = '';
if (!$errors) {
    try {
        $src = (string)file_get_contents($teamPath);
        $ok = strpos($src, 'VERSION: v022') !== false;
        add_check($checks, 'team.php expected source version', $ok, 'VERSION: v022');
        if (!$ok) throw new RuntimeException('team.php is not expected v022 source.');

        $oldToggle = <<<'OLD'
                    <a href="#" role="button" class="dropdown-toggle" id="mrl-user-menu-toggle" aria-haspopup="true" aria-expanded="false">
                        <i class="icon-user"></i>
                        <?php echo teampage_h($first_name); ?> <i class="caret"></i>
                    </a>
OLD;
        $newToggle = <<<'NEW'
                    <a href="#" role="button" class="dropdown-toggle" id="mrl-user-menu-toggle" aria-haspopup="true" aria-expanded="false"
                       onclick="var d=this.parentNode; var o=d.classList.contains('open'); if(o){d.classList.remove('open');this.setAttribute('aria-expanded','false');}else{d.classList.add('open');this.setAttribute('aria-expanded','true');} return false;">
                        <i class="icon-user"></i>
                        <?php echo teampage_h($first_name); ?> <i class="caret"></i>
                    </a>
NEW;
        $count = substr_count($src, $oldToggle);
        add_check($checks, 'Expected v022 user-menu markup', $count === 1, 'matches: ' . $count);
        if ($count !== 1) throw new RuntimeException('Expected v022 user-menu markup not found exactly once.');

        $oldScript = <<<'OLD'
<script>
(function () {
    var toggle = document.getElementById('mrl-user-menu-toggle');
    if (!toggle) {
        return;
    }

    var dropdown = toggle.parentNode;
    if (!dropdown) {
        return;
    }

    function closeMenu() {
        dropdown.classList.remove('open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    function toggleMenu(event) {
        event.preventDefault();
        event.stopPropagation();

        var isOpen = dropdown.classList.contains('open');
        if (isOpen) {
            closeMenu();
        } else {
            dropdown.classList.add('open');
            toggle.setAttribute('aria-expanded', 'true');
        }
    }

    toggle.addEventListener('click', toggleMenu, false);

    document.addEventListener('click', function (event) {
        if (!dropdown.contains(event.target)) {
            closeMenu();
        }
    }, false);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' || event.keyCode === 27) {
            closeMenu();
        }
    }, false);
})();
</script>
OLD;
        $count = substr_count($src, $oldScript);
        add_check($checks, 'Expected v022 deferred menu script', $count === 1, 'matches: ' . $count);
        if ($count !== 1) throw new RuntimeException('Expected v022 deferred menu script not found exactly once.');

        $prepared = replace_once($src, 'VERSION: v022', 'VERSION: v023', 'Version');
        $prepared = replace_once($prepared, 'LAST MODIFIED: 8/20/2026 7:36:02 pm', 'LAST MODIFIED: 8/20/2026 8:29:00 pm', 'Timestamp');
        $oldChangelog = " * CHANGELOG:\n *\n * v022 (8/20/2026 7:36:02 pm)";
        $newChangelog = " * CHANGELOG:\n *\n * v023 (8/20/2026 8:29:00 pm)\n"
            . " * - FIX: User-menu toggle now executes directly on the anchor click.\n"
            . " * - FIX: return false prevents navigation to team.php#.\n"
            . " * - CHANGE: Removes the v022 deferred listener block.\n"
            . " * - PRESERVE: Existing menu links/appearance, charts, routing, pick logic, LP/RD logic, and data.\n *\n"
            . " * v022 (8/20/2026 7:36:02 pm)";
        $prepared = replace_once($prepared, $oldChangelog, $newChangelog, 'Changelog');
        $prepared = replace_once($prepared, $oldToggle, $newToggle, 'Direct onclick');
        $prepared = replace_once($prepared, "\n" . $oldScript . "\n", "\n", 'Remove deferred script');
        add_check($checks, 'Source transformation prepared', true, 'team.php v022 → v023');
    } catch (Throwable $e) {
        $errors[] = 'Preflight transform failed: ' . $e->getMessage();
        add_check($checks, 'Source transformation prepared', false, $e->getMessage());
    }
}

$action = (string)($_POST['action'] ?? '');
if (!$errors && $action === 'install') {
    try {
        $backupDir = dirname($root) . '/mrl_team_user_menu_backup_' . date('Ymd_His');
        if (!@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) throw new RuntimeException('Unable to create backup directory.');
        if (!@copy($teamPath, $backupDir . '/team.php')) throw new RuntimeException('Unable to back up team.php.');
        atomic_write($teamPath, $prepared);
        $after = (string)file_get_contents($teamPath);
        $postflight[]=['name'=>'team.php v023 installed','ok'=>strpos($after,'VERSION: v023')!==false];
        $postflight[]=['name'=>'Direct onclick user-menu toggle installed','ok'=>strpos($after,'onclick="var d=this.parentNode;')!==false];
        $postflight[]=['name'=>'Anchor navigation blocked with return false','ok'=>strpos($after,'return false;">')!==false];
        $postflight[]=['name'=>'v022 deferred listener removed','ok'=>strpos($after,"toggle.addEventListener('click', toggleMenu, false);")===false];
        foreach ($postflight as $pf) if (!$pf['ok']) throw new RuntimeException('Postflight failed: ' . $pf['name']);
        $installed = true;
    } catch (Throwable $e) {
        if ($backupDir && is_file($backupDir . '/team.php')) @copy($backupDir . '/team.php', $teamPath);
        $errors[] = 'INSTALL FAILED; rollback attempted: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>MRL Team User Menu Native Fix Patch v002</title>
<style>*{box-sizing:border-box}body{margin:0;background:#151515;color:#eee;font-family:Tahoma,Verdana,Arial,sans-serif;font-size:14px}.wrap{width:94%;max-width:1050px;margin:18px auto}.banner{border:1px solid #9a7014;background:linear-gradient(180deg,#3a3118,#282417);border-radius:14px;padding:14px 18px;margin-bottom:12px}h1{margin:0;color:#fff1cf;font-size:24px}.sub{color:#d7c49a;margin-top:4px}.card{border:1px solid #4d473f;background:#202020;border-radius:12px;padding:12px 14px;margin:10px 0}h2{color:#ffd08a;font-size:18px;margin:0 0 9px}.ok{color:#62e89b}.bad{color:#ff8181}.small{font-size:12px;color:#aaa;line-height:1.4}table{width:100%;border-collapse:collapse}td{padding:6px 8px;border-bottom:1px solid #333;text-align:left}.btn{font:15px Tahoma;border:1px solid #b18745;border-radius:9px;background:#3a2f1b;color:#ffd08a;padding:9px 14px;cursor:pointer}code{color:#f6d99f}.success{border-color:#286c48;background:#173526}</style></head><body><div class="wrap">
<div class="banner"><h1>MRL Team User Menu Native Fix Patch v002</h1><div class="sub">TESTPHP8 ONLY • generated 8/20/2026 8:29:00 pm • no database changes</div></div>
<div class="card"><h2>Preflight</h2><table><?php foreach($checks as $c): ?><tr><td style="width:40%"><?php echo ih($c['name']); ?></td><td class="<?php echo $c['ok']?'ok':'bad'; ?>"><?php echo $c['ok']?'PASS':'FAIL'; ?></td><td><?php echo ih($c['detail']); ?></td></tr><?php endforeach; ?></table></div>
<?php if($errors): ?><div class="card"><h2 class="bad">Stopped Safely</h2><?php foreach($errors as $e): ?><div class="bad">• <?php echo ih($e); ?></div><?php endforeach; ?></div>
<?php elseif(!$installed && $action!=='install'): ?><div class="card"><h2>Ready to Install</h2><p>Changes only <code>team.php</code>. Replaces the v022 deferred listener with a direct native <code>onclick</code> toggle.</p><form method="post"><button class="btn" name="action" value="install">INSTALL DIRECT USER MENU FIX</button></form></div><?php endif; ?>
<?php if($installed): ?><div class="card success"><h2 class="ok">INSTALL COMPLETE</h2><p><strong>Backup folder:</strong><br><code><?php echo ih($backupDir); ?></code></p><table><?php foreach($postflight as $pf): ?><tr><td><?php echo ih($pf['name']); ?></td><td class="<?php echo $pf['ok']?'ok':'bad'; ?>"><?php echo $pf['ok']?'PASS':'FAIL'; ?></td></tr><?php endforeach; ?></table><p class="small">Test the upper-left name. The menu should open immediately and the address bar should remain on team.php without adding #.</p></div><?php endif; ?>
</div></body></html>
