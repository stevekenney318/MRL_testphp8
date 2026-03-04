// fix_picks.js 2024-02-05 22:36:08

// Event listener for the year dropdown
document.getElementById("yearDropdown").addEventListener("change", function () {
    // Reset the selected variables display
    resetSelectedVariablesDisplay();

    var selectedYear = this.value;
    // Display the selected year on the screen
    document.getElementById("selectedYearDisplay").innerText = "Selected Year: " + selectedYear;

    // Show the team dropdown
    document.getElementById("teamDropdown").style.display = "block";

    // Reset the team dropdown to its initial state
    document.getElementById("teamDropdown").selectedIndex = 0;

    // Reset the selected team display
    document.getElementById("selectedTeamDisplay").innerText = "";

    // Reset the segment dropdown to its initial state
    document.getElementById("segmentDropdown").selectedIndex = 0;

    // Reset the selected segment display
    document.getElementById("selectedSegmentDisplay").innerText = "";

    // Load teams based on the selected year
    loadTeams(selectedYear);
});

document.getElementById("teamDropdown").addEventListener("change", function () {
    var selectedUserID = this.value;
    var selectedYear = document.getElementById("yearDropdown").value;

    // Reset the selected variables display
    resetSelectedVariablesDisplay();

    // Find the selected team information using the team ID
    var selectedTeamInfo = <?php echo json_encode($uniqueTeamsByYear); ?>[selectedYear][selectedUserID];

    // Display the selected team on the screen
    var displayText = selectedTeamInfo['teamName'];

    // Optionally, display the user name as well
    if (selectedTeamInfo['userName']) {
        displayText += " - " + selectedTeamInfo['userName'];
    }

    document.getElementById("selectedTeamDisplay").innerText = displayText;

    // Reset the segment dropdown to its initial state
    document.getElementById("segmentDropdown").selectedIndex = 0;

    // Reset the selected segment display
    document.getElementById("selectedSegmentDisplay").innerText = "";

    // Show the segment dropdown
    document.getElementById("segmentDropdown").style.display = "block";
});

document.getElementById("segmentDropdown").addEventListener("change", function () {
    // Display the selected segment on the screen
    document.getElementById("selectedSegmentDisplay").innerText = "Selected Segment: " + this.value;

    var selectedSegment = this.value;
    var selectedYear = document.getElementById("yearDropdown").value;
    var selectedUserID = document.getElementById("teamDropdown").value;

    // Check if both team and segment are selected before fetching data
    if (selectedUserID && selectedSegment) {
        // Fetch and display data from user_picks based on the selected segment, year, and user ID
        fetchUserPicksData(selectedSegment, selectedYear, selectedUserID);
    }
});

// Define the addDropdownMenus function
function addDropdownMenus(lineCounter) {
    // Create dropdown menu for selecting data from user_picks
    var userPicksDropdown = document.createElement("select");
    userPicksDropdown.id = "userPicksDropdown";
    userPicksDropdown.innerHTML = "<option value='' selected disabled>Select Data from user_picks</option>";
    // Add options corresponding to each lineCounter
    for (var i = 1; i <= lineCounter; i++) {
        var option = document.createElement("option");
        option.value = i;
        option.text = "Data " + i + " from user_picks";
        userPicksDropdown.appendChild(option);
    }
    // Add change event listener to fetch and display selected data from user_picks
    userPicksDropdown.addEventListener("change", function () {
        var selectedLineCounter = this.value;
        fetchUserPickData("user_picks", selectedLineCounter);
    });
    // Append the dropdown menu to the container element
    document.getElementById("userPicksDropdownContainer").appendChild(userPicksDropdown);

    // Create dropdown menu for selecting data from used_picks_history
    var userPicksHistoryDropdown = document.createElement("select");
    userPicksHistoryDropdown.id = "userPicksHistoryDropdown";
    userPicksHistoryDropdown.innerHTML = "<option value='' selected disabled>Select Data from used_picks_history</option>";
    // Add options corresponding to each lineCounter
    for (var i = 1; i <= lineCounter; i++) {
        var option = document.createElement("option");
        option.value = i;
        option.text = "Data " + i + " from used_picks_history";
        userPicksHistoryDropdown.appendChild(option);
    }
    // Add change event listener to fetch and display selected data from used_picks_history
    userPicksHistoryDropdown.addEventListener("change", function () {
        var selectedLineCounter = this.value;
        fetchUserPickData("user_picks_history", selectedLineCounter);
    });
    // Append the dropdown menu to the container element
    document.getElementById("userPicksHistoryDropdownContainer").appendChild(userPicksHistoryDropdown);
}

function fetchUserPicksData(selectedSegment, selectedYear, selectedUserID) {
    // Use JavaScript to send the selected segment, year, and user ID to a PHP script via AJAX
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "fix_picks_fetch.php", true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            // Handle the response from the server for user_picks
            var userPicksData = xhr.responseText;

            // Display the fetched data from user_picks
            document.getElementById("userPicksData").innerHTML = userPicksData;
        }
    };

    // Send the selected segment, year, and user ID to the PHP script for user_picks
    xhr.send("segment=" + selectedSegment + "&year=" + selectedYear + "&userID=" + selectedUserID);
}

function fetchUserPickData(table, selectedLineCounter) {
    // Use JavaScript to send the selected table and line counter to a PHP script via AJAX
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "fix_picks_fetch.php", true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            // Handle the response from the server for user_picks or user_picks_history
            var userPickData = xhr.responseText;

            // Display the fetched data from user_picks or user_picks_history
            if (table === "user_picks") {
                document.getElementById("selectedUserPick").innerText = "Selected User Pick: " + userPickData;
            } else if (table === "user_picks_history") {
                document.getElementById("selectedUserPickHistory").innerText = "Selected User Pick History: " + userPickData;
            }
        }
    };

    // Send the selected table and line counter to the PHP script
    xhr.send("table=" + table + "&lineCounter=" + selectedLineCounter);
}

function resetSelectedVariablesDisplay() {
    // Reset the display of selected variables
    document.getElementById("userPicksData").innerHTML = "";
}

function loadTeams(selectedYear) {
    // Your implementation for loading teams based on the selected year goes here
}
