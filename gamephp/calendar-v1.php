<?php
// Database configuration
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'tournament_scheduler';
// Create connection
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
$conn->set_charset("utf8mb4");

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get all distinct courts/groups from the database
$groups = [];
$sql = "SELECT DISTINCT court FROM (SELECT court FROM scheduled_games UNION SELECT court FROM available_games) AS courts ORDER BY court";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $row = array_map('utf8_encode', $row);
    $groups[] = $row['court'];
}

// If no courts found, use default
$defaultGroups = ['Campo 1', 'Campo 2', 'Campo 3', 'Campo 4'];

foreach ($defaultGroups as $defaultGroup) {
    if (!in_array($defaultGroup, $groups)) {
        $groups[] = $defaultGroup;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'get_games':
                $date = $_POST['date'];
                echo json_encode(getGamesForDate($conn, $date));
                break;
                
            case 'add_game':
                $game = json_decode($_POST['game'], true);
                $date = $_POST['date'];
                echo json_encode(addGame($conn, $game, $date));
                break;
                
            case 'update_game':
                $game = json_decode($_POST['game'], true);
                $date = $_POST['date'];
                echo json_encode(updateGame($conn, $game, $date));
                break;
                
            case 'delete_game':
                $game_id = $_POST['game_id'];
                echo json_encode(deleteGame($conn, $game_id));
                break;
                
            case 'get_available_games':
                echo json_encode(getAvailableGames($conn));
                break;
                
            case 'get_groups':
                echo json_encode($groups);
                break;
        }
    }
    exit;
}

// Helper function to get games for a specific date
function getGamesForDate($conn, $date) {
    $games = array();
    $sql = "SELECT * FROM scheduled_games WHERE game_date = ? ORDER BY start_time";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $row = array_map('utf8_encode', $row);
        $games[] = array(
            'id' => $row['id'],
            'title' => $row['team1'] . ' vs ' . $row['team2'],
            'startTime' => $row['start_time'],
            'endTime' => $row['end_time'],
            'group' => $row['court'],
            'court' => $row['court'],
            'groupColor' => $row['group_color']
        );
    }
    
    return $games;
}

// Helper function to add a new game
function addGame($conn, $game, $date) {
    $teams = explode(' vs ', $game['title']);

    $sql = "INSERT INTO scheduled_games (team1, team2, start_time, end_time, court, group_color, game_date) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        // 🐞 Log prepare error
        return ['success' => false, 'error' => 'Prepare failed: ' . $conn->error];
    }
    
    $team1 = $teams[1];
    // if (!mb_check_encoding($team1, 'UTF-8')) {
    //     $team1 = mb_convert_encoding($team1, 'UTF-8', 'auto');
    // }
    
    $team0 = $teams[0];
    // if (!mb_check_encoding($team0, 'UTF-8')) {
    //     $team0 = mb_convert_encoding($team0, 'UTF-8', 'auto');
    // }

    $stmt->bind_param("sssssis", 
        $team0, $team1, $game['startTime'], $game['startTime'], 
        $game['group'], $game['groupColor'], $date
    );

    if (!$stmt->execute()) {
        // 🐞 Log execute error
        return ['success' => false, 'error' => 'Execute failed: ' . $stmt->error];
    }

    // Delete from available_games if exists
    if (isset($game['originalId'])) {
        $delete_sql = "DELETE FROM available_games WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $game['originalId']);
        $delete_stmt->execute();
    }

    return ['success' => true, 'id' => $stmt->insert_id];
}


// Helper function to update a game
function updateGame($conn, $game, $date) {
    // Parse teams from title
    $teams = explode(' vs ', $game['title']);
    
    $sql = "UPDATE scheduled_games 
            SET team1 = ?, team2 = ?, start_time = ?, end_time = ?, court = ?, group_color = ?, game_date = ?
            WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssisi", 
        $teams[0], $teams[1], $game['startTime'], $game['endTime'], 
        $game['group'], $game['groupColor'], $date, $game['id']
    );
    
    return array('success' => $stmt->execute());
}

// Helper function to delete a game
function deleteGame($conn, $game_id) {
    // First get the game details to potentially move it back to available games
    $sql = "SELECT * FROM scheduled_games WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $game_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $game = $result->fetch_assoc();
    
    if ($game) {
        // Add to available games
        $insert_sql = "INSERT INTO available_games (team1, team2, court, group_color, duration) 
                      VALUES (?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        
        // Calculate duration in hours
        $start = new DateTime($game['start_time']);
        $end = new DateTime($game['end_time']);
        $duration = $start->diff($end)->h + ($start->diff($end)->i / 60);
        
        $insert_stmt->bind_param("sssid", 
            $game['team1'], $game['team2'], $game['court'], 
            $game['group_color'], $duration
        );
        $insert_stmt->execute();
    }
    
    // Now delete from scheduled games
    $delete_sql = "DELETE FROM scheduled_games WHERE id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $game_id);
    
    return array('success' => $delete_stmt->execute());
}

// Helper function to get available games
function getAvailableGames($conn) {
    $games = array();
    $sql = "SELECT * FROM available_games";
    $result = $conn->query($sql);
    
    while ($row = $result->fetch_assoc()) {
        $games[$row['id']] = array(
            'id' => $row['id'],
            'title' => $row['team1'] . ' vs ' . $row['team2'],
            'group' => $row['court'],
            'court' => $row['court'],
            'groupColor' => $row['group_color'],
            'duration' => $row['duration']
        );
    }
    
    return $games;
}

// Set default date to today or a specific date for demo
$current_date = isset($_GET['date']) ? $_GET['date'] : '2025-06-23';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tournament Schedule</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
  <style>
    /* Base styles */
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f8f9fa;
      padding: 20px;
      margin: 0;
    }
    
    /* Main container */
    .main-container {
      display: flex;
      width: 100%;
      gap: 20px;
    }
    
    /* Calendar container - 80% width */
    .calendar-container {
      flex: 0 0 80%;
      background-color: white;
      border-radius: 10px;
      box-shadow: 0 0 20px rgba(0,0,0,0.1);
      overflow: hidden;
    }
    
    /* Events sidebar - 20% width */
    .events-sidebar {
      flex: 0 0 20%;
      background-color: white;
      border-radius: 10px;
      box-shadow: 0 0 20px rgba(0,0,0,0.1);
      padding: 20px;
      overflow-y: auto;
      max-height: calc(100vh - 40px);
      position: sticky;
      top: 20px;
    }
    
    .sidebar-header {
      font-size: 1.25rem;
      font-weight: bold;
      margin-bottom: 20px;
      padding-bottom: 10px;
      border-bottom: 2px solid #667eea;
      color: #495057;
    }
    
    .event-item {
      padding: 12px;
      margin-bottom: 10px;
      border-radius: 5px;
      background-color: #f8f9fa;
      transition: all 0.2s;
      cursor: move;
      border-left: 4px solid transparent;
    }
    
    .event-item:hover {
      transform: translateX(3px);
      box-shadow: 0 3px 5px rgba(0,0,0,0.1);
    }
    
    .event-item[data-group-color="1"] {
      border-left-color: #667eea;
    }
    .event-item[data-group-color="2"] {
      border-left-color: #f5576c;
    }
    .event-item[data-group-color="3"] {
      border-left-color: #4facfe;
    }
    .event-item[data-group-color="4"] {
      border-left-color: #5ee7df;
    }
    .event-item[data-group-color="5"] {
      border-left-color: #a18cd1;
    }
    .event-item[data-group-color="6"] {
      border-left-color: #ff9a9e;
    }
    .event-item[data-group-color="7"] {
      border-left-color: #a1c4fd;
    }
    .event-item[data-group-color="8"] {
      border-left-color: #ffecd2;
    }
    .event-item[data-group-color="9"] {
      border-left-color: #84fab0;
    }
    .event-item[data-group-color="10"] {
      border-left-color: #ffc3a0;
    }
    
    .event-teams {
      font-weight: 500;
      font-size: 0.9rem;
    }
    
    .event-court {
      font-size: 0.75rem;
      color: #6c757d;
      margin-top: 5px;
    }
    
    /* Header styles */
    .calendar-header {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 15px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    
    /* Date navigation */
    .date-nav {
      display: flex;
      gap: 10px;
      padding: 15px;
      background-color: #f1f3f5;
      border-bottom: 1px solid #dee2e6;
    }
    .date-item {
      flex: 1;
      text-align: center;
      padding: 10px;
      border-radius: 5px;
      cursor: pointer;
      transition: all 0.2s;
    }
    .date-item:hover {
      background-color: #e9ecef;
    }
    .date-item.active {
      background-color: #667eea;
      color: white;
      font-weight: bold;
    }
    
    /* Calendar grid layout - dynamic columns */
    .calendar-grid {
      display: grid;
      grid-template-columns: 80px repeat(var(--num-groups), minmax(120px, 1fr));
      width: 100%;
      overflow-x: auto;
    }
    
    .grid-header {
      background-color: #495057;
      color: white;
      padding: 12px;
      text-align: center;
      font-weight: bold;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    
    .group-header {
      background-color: #6c757d;
      color: white;
      padding: 12px;
      text-align: center;
      font-weight: bold;
      position: sticky;
      top: 0;
      z-index: 10;
      white-space: normal;
      word-break: break-word;
    }
    
    .time-slot {
      background-color: #f8f9fa;
      padding: 10px;
      border-right: 1px solid #dee2e6;
      border-bottom: 1px solid #dee2e6;
      text-align: center;
      font-weight: 500;
      position: sticky;
      left: 0;
      z-index: 2;
    }
    
    .game-slot {
      padding: 5px;
      border-right: 1px solid #dee2e6;
      border-bottom: 1px solid #dee2e6;
      min-height: 60px;
      position: relative;
      min-width: 120px;
    }
    
    /* Event styles */
    .game-event {
      background-color: #e9ecef;
      border-radius: 5px;
      padding: 8px;
      margin: 2px;
      font-size: 12px;
      cursor: move;
      transition: all 0.2s;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .game-event:hover {
      transform: translateY(-2px);
      box-shadow: 0 3px 5px rgba(0,0,0,0.1);
    }
    
    /* Color classes based on data attributes */
    .game-event[data-group-color="1"] {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
    }
    .game-event[data-group-color="2"] {
      background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
      color: white;
    }
    .game-event[data-group-color="3"] {
      background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
      color: white;
    }
    .game-event[data-group-color="4"] {
      background: linear-gradient(135deg, #5ee7df 0%, #66a6ff 100%);
      color: white;
    }
    .game-event[data-group-color="5"] {
      background: linear-gradient(135deg, #a18cd1 0%, #fbc2eb 100%);
      color: white;
    }
    .game-event[data-group-color="6"] {
      background: linear-gradient(135deg, #ff9a9e 0%, #fad0c4 100%);
      color: white;
    }
    .game-event[data-group-color="7"] {
      background: linear-gradient(135deg, #a1c4fd 0%, #c2e9fb 100%);
      color: white;
    }
    .game-event[data-group-color="8"] {
      background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
      color: #495057;
    }
    .game-event[data-group-color="9"] {
      background: linear-gradient(135deg, #84fab0 0%, #8fd3f4 100%);
      color: white;
    }
    .game-event[data-group-color="10"] {
      background: linear-gradient(135deg, #ffc3a0 0%, #ffafbd 100%);
      color: white;
    }
    
    /* Utility classes */
    .empty-slot {
      background-color: #f8f9fa;
      height: 100%;
    }
    .current-time {
      background-color: rgba(102, 126, 234, 0.1);
      border-left: 3px solid #667eea;
    }
    .dragging {
      opacity: 0.5;
      border: 2px dashed #667eea;
    }
    .drop-target {
      background-color: rgba(102, 126, 234, 0.2);
      border: 2px dashed #667eea;
    }
    
    /* Responsive styles */
    @media (max-width: 1200px) {
      .main-container {
        flex-direction: column;
      }
      .calendar-container, .events-sidebar {
        flex: 0 0 100%;
        width: 100%;
      }
      .events-sidebar {
        max-height: 300px;
      }
      
      .calendar-grid {
        grid-template-columns: 60px repeat(var(--num-groups), minmax(100px, 1fr));
      }
    }
    
    @media (max-width: 768px) {
      .calendar-grid {
        grid-template-columns: 50px repeat(var(--num-groups), minmax(80px, 1fr));
      }
      
      .game-event {
        font-size: 10px;
        padding: 3px;
      }
      
      .date-item {
        padding: 5px;
        font-size: 0.8rem;
      }
    }
  </style>
</head>
<body>
  <div class="main-container">
    <div class="calendar-container">
      <div class="calendar-header">
        <h2 class="m-0">Tournament Schedule</h2>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-light" id="prev-week">
            <i class="bi bi-chevron-left"></i>
          </button>
          <button class="btn btn-sm btn-outline-light" id="next-week">
            <i class="bi bi-chevron-right"></i>
          </button>
        </div>
      </div>
      
      <div class="date-nav" id="date-nav">
        <!-- Dates will be populated by JavaScript -->
      </div>
      
      <div class="calendar-grid" id="calendar-grid">
        <!-- Grid will be populated by JavaScript -->
      </div>
    </div>

    <div class="events-sidebar">
      <div class="sidebar-header">Available Matches</div>
      <div id="events-list" class="d-flex flex-column gap-3">
        <!-- Events will be populated by JavaScript -->
      </div>
    </div>
  </div>

  <!-- Game Details Modal -->
  <div class="modal fade" id="gameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="gameModalTitle">Game Details</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <h6 id="gameTeams"></h6>
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi bi-calendar"></i>
              <span id="gameDate"></span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi bi-clock"></i>
              <span id="gameTime"></span>
            </div>
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi bi-people"></i>
              <span id="gameGroup"></span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-geo-alt"></i>
              <span id="gameCourt"></span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Toast for success messages -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 11">
    <div id="successToast" class="toast align-items-center text-white bg-success" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body">
          Game successfully scheduled!
        </div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // Initialize with current date
    let currentDate = new Date('<?php echo $current_date; ?>');
    const gameModal = new bootstrap.Modal(document.getElementById('gameModal'));
    const successToast = new bootstrap.Toast(document.getElementById('successToast'));
    let draggedGame = null;
    let draggedGameElement = null;
    let isDraggingFromCalendar = false;
    
    // Will be populated from server
    let groups = [];
    
    // Generate time slots from 6:00 AM to 10:00 PM
    const timeSlots = [];
    for (let hour = 6; hour <= 22; hour++) {
      timeSlots.push(`${hour.toString().padStart(2, '0')}:00`);
    }

    // Initialize the calendar
    async function initCalendar() {
      // First get groups from server
      await fetchGroups();
      renderDateNavigation();
      renderCalendarGrid(currentDate);
      refreshEventsSidebar();
      setupDragAndDrop();
    }

    // Fetch groups from server
    async function fetchGroups() {
      const response = await fetch('index.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_groups'
      });
      groups = await response.json();
    }

    // Render the date navigation
    function renderDateNavigation() {
      const dateNav = document.getElementById('date-nav');
      dateNav.innerHTML = '';
      
      // Create 5 date items (current date and next 4 days)
      for (let i = 0; i < 5; i++) {
        const date = new Date(currentDate);
        date.setDate(currentDate.getDate() + i);
        
        const dateItem = document.createElement('div');
        dateItem.className = `date-item ${i === 0 ? 'active' : ''}`;
        dateItem.dataset.date = formatDate(date);
        
        const dayName = date.toLocaleDateString('en-US', { weekday: 'short' });
        const dayNumber = date.getDate();
        const monthName = date.toLocaleDateString('en-US', { month: 'short' });
        
        dateItem.innerHTML = `
          <div class="fw-bold">${dayName}</div>
          <div>${dayNumber} ${monthName}</div>
        `;
        
        dateItem.addEventListener('click', () => {
          document.querySelectorAll('.date-item').forEach(item => item.classList.remove('active'));
          dateItem.classList.add('active');
          currentDate = new Date(dateItem.dataset.date);
          renderCalendarGrid(currentDate);
          setupDragAndDrop();
        });
        
        dateNav.appendChild(dateItem);
      }
    }

    // Render the calendar grid for a specific date
    function renderCalendarGrid(date) {
      const calendarGrid = document.getElementById('calendar-grid');
      calendarGrid.innerHTML = '';
      
      // Set CSS variable for number of groups
      calendarGrid.style.setProperty('--num-groups', groups.length);
      
      // Create header row
      calendarGrid.appendChild(createGridHeader('Time'));
      groups.forEach(group => {
        calendarGrid.appendChild(createGridHeader(group));
      });
      
      // Create time slots
      const dateStr = formatDate(date);
      
      // Fetch games for this date from server
      fetchGamesForDate(dateStr).then(gamesForDate => {
        timeSlots.forEach(timeSlot => {
          // Time cell
          calendarGrid.appendChild(createTimeSlot(timeSlot));
          
          // Group cells
          groups.forEach(group => {
            const gamesInSlot = gamesForDate.filter(game => 
              game.group === group && isTimeInSlot(timeSlot, game.startTime, game.endTime)
            );
            
            const groupCell = document.createElement('div');
            groupCell.className = 'game-slot';
            groupCell.dataset.group = group;
            groupCell.dataset.timeSlot = timeSlot;
            
            if (gamesInSlot.length > 0) {
              gamesInSlot.forEach(game => {
                const gameElement = document.createElement('div');
                gameElement.className = 'game-event';
                gameElement.innerHTML = game.title.split(' vs ').join('<br>vs ');
                gameElement.dataset.gameId = game.id;
                gameElement.dataset.groupColor = game.groupColor;
                gameElement.draggable = true;
                
                gameElement.addEventListener('click', (e) => {
                  e.stopPropagation();
                  showGameDetails(game);
                });
                
                gameElement.addEventListener('dragstart', (e) => {
                  draggedGame = game;
                  draggedGameElement = e.target;
                  isDraggingFromCalendar = true;
                  e.target.classList.add('dragging');
                  e.dataTransfer.setData('text/plain', game.id);
                  e.dataTransfer.effectAllowed = 'move';
                });
                
                gameElement.addEventListener('dragend', (e) => {
                  e.target.classList.remove('dragging');
                  isDraggingFromCalendar = false;
                });
                
                groupCell.appendChild(gameElement);
              });
            } else {
              groupCell.innerHTML = '<div class="empty-slot"></div>';
            }
            
            // Highlight current time
            if (isCurrentTime(timeSlot, dateStr)) {
              groupCell.classList.add('current-time');
            }
            
            calendarGrid.appendChild(groupCell);
          });
        });
        
        // Set up drag and drop for the new elements
        setupDragAndDrop();
      });
    }

    // Fetch games for a specific date from the server
    async function fetchGamesForDate(date) {
      const response = await fetch('index.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=get_games&date=${date}`
      });
      
      return await response.json();
    }

    // Refresh the events sidebar
    function refreshEventsSidebar() {
      const eventsList = document.getElementById('events-list');
      eventsList.innerHTML = '';
      
      // Fetch available games from server
      fetchAvailableGames().then(availableGames => {
        Object.values(availableGames).forEach(game => {
          const eventItem = document.createElement('div');
          eventItem.className = 'event-item';
          eventItem.dataset.groupColor = game.groupColor;
          eventItem.dataset.gameId = game.id;
          eventItem.draggable = true;
          
          eventItem.innerHTML = `
            <div class="event-teams">${game.title}</div>
            <div class="event-court">${game.court}</div>
          `;
          
          eventItem.addEventListener('dragstart', (e) => {
            draggedGame = game;
            draggedGameElement = e.target;
            isDraggingFromCalendar = false;
            e.target.classList.add('dragging');
            e.dataTransfer.setData('text/plain', game.id);
            e.dataTransfer.effectAllowed = 'copy';
          });
          
          eventItem.addEventListener('dragend', (e) => {
            e.target.classList.remove('dragging');
          });
          
          eventsList.appendChild(eventItem);
        });
      });
    }

    // Fetch available games from server
    async function fetchAvailableGames() {
      const response = await fetch('index.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=get_available_games'
      });
      
      return await response.json();
    }

    // Set up drag and drop functionality
    function setupDragAndDrop() {
      const gameSlots = document.querySelectorAll('.game-slot');
      
      // Drag over for calendar slots
      gameSlots.forEach(slot => {
        slot.addEventListener('dragover', (e) => {
          e.preventDefault();
          e.dataTransfer.dropEffect = isDraggingFromCalendar ? 'move' : 'copy';
          slot.classList.add('drop-target');
        });
        
        slot.addEventListener('dragleave', () => {
          slot.classList.remove('drop-target');
        });
        
        slot.addEventListener('drop', async (e) => {
          e.preventDefault();
          slot.classList.remove('drop-target');
          
          if (!draggedGame) return;
          
          const newGroup = slot.dataset.group;
          const newTimeSlot = slot.dataset.timeSlot;
          const newHour = parseInt(newTimeSlot.split(':')[0]);
          
          // Calculate new start and end times
          const duration = isDraggingFromCalendar ? 
            getDurationInHours(draggedGame) : 
            draggedGame.duration;
          
          const newStartTime = `${newHour.toString().padStart(2, '0')}:00`;
          const newEndTime = `${(newHour + duration).toString().padStart(2, '0')}:${(duration % 1 === 0.5) ? '30' : '00'}`;
          
          // Get the current date
          const dateStr = formatDate(currentDate);
          
          let updatedGame = {
            ...draggedGame,
            startTime: newStartTime,
            endTime: newEndTime,
            group: newGroup,
            court: newGroup
          };
          
          try {
            let response;
            
            if (isDraggingFromCalendar) {
              // Moving an existing game
              response = await updateGameOnServer(updatedGame, dateStr);
            } else {
              // Adding a new game from available list
              updatedGame.originalId = updatedGame.id;
              delete updatedGame.id;
              response = await addGameToServer(updatedGame, dateStr);
            }
            
            if (response.success) {
              // Show success message
              successToast.show();
              
              // Re-render the calendar to show the new event
              renderCalendarGrid(currentDate);
              
              // Refresh the events sidebar if we moved an external event
              if (!isDraggingFromCalendar) {
                refreshEventsSidebar();
              }
            }
          } catch (error) {
            console.error('Error updating game:', error);
            // Handle error (show error message, revert changes, etc.)
          }
        });
      });
    }

    // Function to add game to server
    async function addGameToServer(game, dateStr) {
      const response = await fetch('index.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=add_game&game=${JSON.stringify(game)}&date=${dateStr}`
      });
      
      return await response.json();
    }

    // Function to update game on server
    async function updateGameOnServer(game, dateStr) {
      const response = await fetch('index.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=update_game&game=${JSON.stringify(game)}&date=${dateStr}`
      });
      
      return await response.json();
    }

    // Helper function to create grid header
    function createGridHeader(text) {
      const header = document.createElement('div');
      header.className = text === 'Time' ? 'grid-header' : 'group-header';
      header.textContent = text;
      return header;
    }

    // Helper function to create time slot
    function createTimeSlot(time) {
      const timeSlot = document.createElement('div');
      timeSlot.className = 'time-slot';
      timeSlot.textContent = formatTimeDisplay(time);
      return timeSlot;
    }

    // Helper function to check if a game falls in this time slot
    function isTimeInSlot(slotTime, gameStart, gameEnd) {
      const slotHour = parseInt(slotTime.split(':')[0]);
      const gameStartHour = parseInt(gameStart.split(':')[0]);
      const gameEndHour = parseInt(gameEnd.split(':')[0]);
      
      return (gameStartHour <= slotHour && slotHour < gameEndHour) || 
             (gameStartHour === slotHour);
    }

    // Helper function to get duration in hours
    function getDurationInHours(game) {
      const start = parseInt(game.startTime.split(':')[0]);
      const end = parseInt(game.endTime.split(':')[0]);
      return end - start;
    }

    // Helper function to check if this is the current time
    function isCurrentTime(slotTime, dateStr) {
      const now = new Date();
      const today = formatDate(now);
      
      if (today !== dateStr) return false;
      
      const currentHour = now.getHours();
      const slotHour = parseInt(slotTime.split(':')[0]);
      
      return currentHour === slotHour;
    }

    // Show game details in modal
    function showGameDetails(game) {
      document.getElementById('gameModalTitle').textContent = game.group;
      document.getElementById('gameTeams').textContent = game.title;
      document.getElementById('gameDate').textContent = currentDate.toLocaleDateString('en-US', { 
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' 
      });
      document.getElementById('gameTime').textContent = `${game.startTime} - ${game.endTime}`;
      document.getElementById('gameGroup').textContent = game.group;
      document.getElementById('gameCourt').textContent = game.court;
      
      gameModal.show();
    }

    // Helper function to format date as YYYY-MM-DD
    function formatDate(date) {
      const year = date.getFullYear();
      const month = (date.getMonth() + 1).toString().padStart(2, '0');
      const day = date.getDate().toString().padStart(2, '0');
      return `${year}-${month}-${day}`;
    }

    // Helper function to format time display
    function formatTimeDisplay(time) {
      const hour = parseInt(time.split(':')[0]);
      return hour > 12 ? `${hour - 12} PM` : hour === 12 ? '12 PM' : `${hour} AM`;
    }

    // Navigation buttons
    document.getElementById('prev-week').addEventListener('click', () => {
      currentDate.setDate(currentDate.getDate() - 7);
      initCalendar();
    });

    document.getElementById('next-week').addEventListener('click', () => {
      currentDate.setDate(currentDate.getDate() + 7);
      initCalendar();
    });

    // Initialize the calendar
    initCalendar();
  </script>
</body>
</html>