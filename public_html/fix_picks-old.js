function fetchUserPicksData(selectedSegment, selectedYear, selectedUserID) {
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "fix_picks_fetch.php", true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            var userPicksData = xhr.responseText;
            document.getElementById("userPicksData").innerHTML = userPicksData;
        }
    };
    xhr.send("table=user_picks&segment=" + selectedSegment + "&year=" + selectedYear + "&userID=" + selectedUserID);
}

document.getElementById("yearDropdown").addEventListener("change", function () {
    resetSelectedVariablesDisplay();

    var selectedYear = this.value;
    document.getElementById("selectedYearDisplay").innerText = "Selected Year: " + selectedYear;

    loadTeams(selectedYear);
});

document.getElementById("teamDropdown").addEventListener("change", function () {
    var selectedUserID = this.value;
    var selectedYear = document.getElementById("yearDropdown").value;

    resetSelectedVariablesDisplay();

    var selectedTeamInfo = uniqueTeamsByYear[selectedYear][selectedUserID];

    var displayText = selectedTeamInfo['teamName'];
    if (selectedTeamInfo['userName']) {
        displayText += " - " + selectedTeamInfo['userName'];
    }
    document.getElementById("selectedTeamDisplay").innerText = displayText;

    document.getElementById("segmentDropdown").selectedIndex = 0;
    document.getElementById("selectedSegmentDisplay").innerText = "";

    document.getElementById("segmentDropdown").style.display = "block";
});

document.getElementById("segmentDropdown").addEventListener("change", function () {
    // Reset the display of selected segment
    document.getElementById("selectedSegmentDisplay").innerText = "";

    var selectedSegment = this.value;
    var selectedYear = document.getElementById("yearDropdown").value;
    var selectedUserID = document.getElementById("teamDropdown").value;

    // Reset the display of user picks data
    resetSelectedVariablesDisplay();

    // Check if a segment is selected
    if (selectedSegment) {
        // Display the selected segment on the screen
        document.getElementById("selectedSegmentDisplay").innerText = "Selected Segment: " + selectedSegment;

        // Fetch and display data from user_picks based on the selected segment, year, and user ID
        fetchUserPicksData(selectedSegment, selectedYear, selectedUserID);
    }
});

function resetSelectedVariablesDisplay() {
    document.getElementById("userPicksData").innerHTML = "";
    document.getElementById("selectedUserPicksLine").innerHTML = "";
    document.getElementById("selectedUserPicksHistoryLine").innerHTML = "";
}

function loadTeams(selectedYear) {
    var teamDropdown = document.getElementById("teamDropdown");

    teamDropdown.options.length = 1;

    var teamsInSelectedYear = uniqueTeamsByYear[selectedYear] || {};
    Object.keys(teamsInSelectedYear).forEach(function (userID) {
        var option = document.createElement("option");
        option.value = userID;
        option.text = teamsInSelectedYear[userID]['teamName'];
        teamDropdown.add(option);
    });
}
