<?php
// team_name.php
// Standalone Team Name module to be included by team.php
// - Handles AJAX "check availability" (no submit, no reload)
// - Handles POST save team name
// - Renders the inline "set team name" form
//
// Requirements from the including page:
// - session_start() already called
// - $dbconnect (mysqli) available from config.php
// - $raceYear available from config_mrl.php
// - $uid = (int)$_SESSION['userSession'] available

function mrl_teamname_get_lock_flag(mysqli $dbconnect, string $raceYear): string
{
    $lockTeamName = 'Y';

    $raceYearEsc = mysqli_real_escape_string($dbconnect, $raceYear);
    $lockRes = mysqli_query(
        $dbconnect,
        "SELECT lockTeamName
         FROM years
         WHERE year = '$raceYearEsc'
         LIMIT 1"
    );

    if ($lockRes && mysqli_num_rows($lockRes) === 1) {
        $lockRow = mysqli_fetch_assoc($lockRes);
        $lockTeamName = strtoupper(trim($lockRow['lockTeamName'] ?? 'Y'));
    }

    return $lockTeamName;
}

function mrl_teamname_handle_ajax(mysqli $dbconnect): void
{
    // AJAX check: team.php?ajaxCheckTeamName=Some+Name
    if (!isset($_GET['ajaxCheckTeamName'])) {
        return;
    }

    $candidate = trim((string)$_GET['ajaxCheckTeamName']);

    if ($candidate === '') {
        echo 'EMPTY';
        exit;
    }

    $candidateEsc = mysqli_real_escape_string($dbconnect, $candidate);

    $chk = mysqli_query(
        $dbconnect,
        "SELECT ID
         FROM teams
         WHERE teamName = '$candidateEsc'
         LIMIT 1"
    );

    echo ($chk && mysqli_num_rows($chk) > 0) ? 'TAKEN' : 'AVAILABLE';
    exit;
}

function mrl_teamname_handle_save(mysqli $dbconnect, string $raceYear, int $uid): string
{
    // Returns message string ('' when no message or success redirect happened)
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['teamNameSubmit'])) {
        return '';
    }

    $lockTeamName = mrl_teamname_get_lock_flag($dbconnect, $raceYear);

    if ($lockTeamName === 'Y') {
        return "Team names are locked for the $raceYear season.";
    }

    $mode = $_POST['teamNameMode'] ?? '';
    $selectedName = '';
    $teamNameMessage = '';

    // Option A: previous name
    if ($mode === 'previous') {
        $prevRes = mysqli_query(
            $dbconnect,
            "SELECT teamName
             FROM user_teams
             WHERE userID = $uid
               AND raceYear < $raceYear
               AND TRIM(IFNULL(teamName,'')) <> ''
             ORDER BY raceYear DESC
             LIMIT 1"
        );
        if ($prevRes && mysqli_num_rows($prevRes) === 1) {
            $prevRow = mysqli_fetch_assoc($prevRes);
            $selectedName = trim($prevRow['teamName'] ?? '');
        }
        if ($selectedName === '') {
            $teamNameMessage = "No previous team name was found for your account.";
        }
    }

    // Option B: choose from your prior names
    if ($mode === 'choose') {
        $selectedName = trim($_POST['existingTeamName'] ?? '');
        if ($selectedName === '') {
            $teamNameMessage = "Please choose one of your existing team names.";
        } else {
            // Verify belongs to this user (prevents tampering)
            $escName = mysqli_real_escape_string($dbconnect, $selectedName);
            $verify = mysqli_query(
                $dbconnect,
                "SELECT ID
                 FROM user_teams
                 WHERE userID = $uid
                   AND teamName = '$escName'
                 LIMIT 1"
            );
            if (!$verify || mysqli_num_rows($verify) === 0) {
                $teamNameMessage = "Invalid selection.";
            }
        }
    }

    // Option C: new name
    if ($mode === 'new') {
        $selectedName = trim($_POST['newTeamName'] ?? '');
        if ($selectedName === '') {
            $teamNameMessage = "Please enter a new team name.";
        }
    }

    if ($mode !== 'previous' && $mode !== 'choose' && $mode !== 'new') {
        $teamNameMessage = "Please select a team name option.";
    }

    // Normalize spacing + length guard
    if ($teamNameMessage === '') {
        $selectedName = preg_replace('/\s+/', ' ', $selectedName);
        $selectedName = trim($selectedName);
    }
    if ($teamNameMessage === '' && strlen($selectedName) > 50) {
        $teamNameMessage = "Team name is too long (max 50 characters).";
    }

    if ($teamNameMessage !== '') {
        return $teamNameMessage;
    }

    $esc = mysqli_real_escape_string($dbconnect, $selectedName);

    // If NEW, must not already exist in teams
    if ($mode === 'new') {
        $exists = mysqli_query(
            $dbconnect,
            "SELECT ID
             FROM teams
             WHERE teamName = '$esc'
             LIMIT 1"
        );
        if ($exists && mysqli_num_rows($exists) > 0) {
            return "That team name is already taken. Please choose a different name.";
        }
    }

// Only create a team record when creating a NEW name
if ($mode === 'new') {
    mysqli_query(
        $dbconnect,
        "INSERT INTO teams (teamName, entryDate)
         VALUES ('$esc', NOW())"
    );
}


    // Upsert user_teams for this user/year
    mysqli_query(
        $dbconnect,
        "INSERT INTO user_teams (userID, teamName, raceYear)
         VALUES ($uid, '$esc', $raceYear)
         ON DUPLICATE KEY UPDATE teamName='$esc'"
    );

    header("Location: " . $_SERVER['PHP_SELF'] . "?teamNameSaved=1");
    exit;
}

function mrl_teamname_render_form(mysqli $dbconnect, string $raceYear, int $uid, string $teamNameMessage = ''): void
{
    $lockTeamName = mrl_teamname_get_lock_flag($dbconnect, $raceYear);

    // Previous team name
    $prevTeamName = '';
    $prevRes = mysqli_query(
        $dbconnect,
        "SELECT teamName
         FROM user_teams
         WHERE userID = $uid
           AND raceYear < $raceYear
           AND TRIM(IFNULL(teamName,'')) <> ''
         ORDER BY raceYear DESC
         LIMIT 1"
    );
    if ($prevRes && mysqli_num_rows($prevRes) === 1) {
        $prevRow = mysqli_fetch_assoc($prevRes);
        $prevTeamName = trim($prevRow['teamName'] ?? '');
    }

    // Dropdown list: names this user has used (distinct)
    $userNames = [];
    $namesRes = mysqli_query(
        $dbconnect,
        "SELECT DISTINCT teamName
         FROM user_teams
         WHERE userID = $uid
           AND TRIM(IFNULL(teamName,'')) <> ''
         ORDER BY teamName"
    );
    if ($namesRes) {
        while ($nr = mysqli_fetch_assoc($namesRes)) {
            $nm = trim($nr['teamName'] ?? '');
            if ($nm !== '') {
                $userNames[] = $nm;
            }
        }
    }

    // Success flash
    if (isset($_GET['teamNameSaved'])) {
        echo "<div style='color:#00cc66; font-weight:bold; font-size:16pt; text-align:center; margin-bottom:10px;'>Team name saved successfully.</div>";
    }

    echo "<div style='border:1px solid #555; background:#1f1f1f; padding:14px; max-width:850px; margin:0 auto;'>";
    echo "<div style='color:red; font-weight:bold; font-size:16pt; text-align:center; margin-bottom:10px;'>Please set your team name for the $raceYear season before continuing.</div>";

    if (trim($teamNameMessage) !== '') {
        echo "<div style='color:red; font-weight:bold; font-size:14pt; text-align:center; margin-bottom:10px;'>" .
             htmlspecialchars($teamNameMessage, ENT_QUOTES, 'UTF-8') .
             "</div>";
    }

    if ($lockTeamName === 'Y') {
        echo "<div style='color:red; font-weight:bold; font-size:14pt; text-align:center;'>Team names are locked for the $raceYear season.</div>";
        echo "</div>";
        return;
    }

    ?>

    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'); ?>" onsubmit="return confirmTeamNameSubmit();">

        <?php if ($prevTeamName !== '') { ?>
            <div style="margin-bottom:10px;">
                <label style="color:#dfcca8; font-size:14pt;">
                    <input type="radio" name="teamNameMode" value="previous" style="margin-right:8px;">
                    Use previous team name:
                    <span style="color:#00cc66; font-weight:bold;">"<?php echo htmlspecialchars($prevTeamName, ENT_QUOTES, 'UTF-8'); ?>"</span>
                </label>
            </div>
        <?php } ?>

        <div style="margin-bottom:10px;">
            <label style="color:#dfcca8; font-size:14pt;">
                <input type="radio" name="teamNameMode" value="choose" style="margin-right:8px;">
                Choose from your previous team names:
            </label><br>
            <select name="existingTeamName" id="existingTeamName"
                    style="margin-top:6px; font-size:13pt; padding:3px; width:420px;">
                <option value="">-- Select --</option>
                <?php foreach ($userNames as $nm) { ?>
                    <option value="<?php echo htmlspecialchars($nm, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($nm, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php } ?>
            </select>
        </div>

        <div style="margin-bottom:12px;">
            <label style="color:#dfcca8; font-size:14pt;">
                <input type="radio" name="teamNameMode" value="new" id="teamModeNew" style="margin-right:8px;">
                Create a new team name (must be unique):
            </label><br>

            <input type="text" name="newTeamName" id="newTeamName" maxlength="50"
                   style="margin-top:6px; font-size:13pt; padding:3px; width:300px;"
                   placeholder="Enter new team name">

            <input type="button" id="checkTeamNameBtn"
                   value="Check availability"
                   style="margin-left:8px; font-size:12pt; padding:3px 10px;">

            <span id="availabilityResult"
                  style="margin-left:10px; font-size:13pt; font-weight:bold;"></span>
        </div>

        <div style="text-align:center;">
            <input type="submit" name="teamNameSubmit" value="Save Team Name" style="font-size:13pt; padding:4px 14px; cursor:pointer;">
        </div>

    </form>

    <script>
        // Auto-select radio buttons to prevent confusion
        (function () {
            var chooseRadio = document.querySelector('input[name="teamNameMode"][value="choose"]');
            var newRadio = document.getElementById('teamModeNew');

            var dropdown = document.getElementById('existingTeamName');
            if (dropdown) {
                dropdown.addEventListener('focus', function () { if (chooseRadio) chooseRadio.checked = true; });
                dropdown.addEventListener('change', function () { if (chooseRadio) chooseRadio.checked = true; });
                dropdown.addEventListener('click', function () { if (chooseRadio) chooseRadio.checked = true; });
            }

            var newInput = document.getElementById('newTeamName');
            if (newInput) {
                newInput.addEventListener('focus', function () { if (newRadio) newRadio.checked = true; });
                newInput.addEventListener('input', function () { if (newRadio) newRadio.checked = true; });
                newInput.addEventListener('click', function () { if (newRadio) newRadio.checked = true; });
            }
        })();

        // Inline availability check (no submit)
        document.getElementById('checkTeamNameBtn').addEventListener('click', function () {
            var name = (document.getElementById('newTeamName').value || '').trim();
            var result = document.getElementById('availabilityResult');

            result.textContent = '';
            result.style.color = '';

            if (!name) {
                result.textContent = 'Enter a name';
                result.style.color = '#ff0000';
                return;
            }

            fetch('?ajaxCheckTeamName=' + encodeURIComponent(name))
                .then(function (r) { return r.text(); })
                .then(function (resp) {
                    if (resp === 'AVAILABLE') {
                        result.textContent = 'Available';
                        result.style.color = '#00cc66';
                    } else if (resp === 'TAKEN') {
                        result.textContent = 'Already in use';
                        result.style.color = '#ff0000';
                    } else {
                        result.textContent = 'Error checking name';
                        result.style.color = '#ff0000';
                    }
                })
                .catch(function () {
                    result.textContent = 'Error checking name';
                    result.style.color = '#ff0000';
                });
        });

        function confirmTeamNameSubmit() {
            var mode = "";
            var radios = document.getElementsByName("teamNameMode");
            for (var i = 0; i < radios.length; i++) {
                if (radios[i].checked) {
                    mode = radios[i].value;
                    break;
                }
            }

            if (!mode) {
                alert("Please select a team name option.");
                return false;
            }

            var chosen = "";
            if (mode === "previous") {
                chosen = <?php echo json_encode($prevTeamName); ?>;
            } else if (mode === "choose") {
                var sel = document.getElementById("existingTeamName");
                chosen = sel ? sel.value : "";
            } else if (mode === "new") {
                var inp = document.getElementById("newTeamName");
                chosen = inp ? inp.value : "";
            }

            chosen = (chosen || "").trim();
            if (!chosen) {
                alert("Please choose or enter a team name.");
                return false;
            }

            return confirm('Set your team name to: "' + chosen + '" ?');
        }
    </script>

    <?php
    echo "</div>";
}
