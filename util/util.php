<?php

// returns true if any of the parameters passed to it are set in the POST environment
function anySet() {
  foreach(func_get_args() as $item) {
    if(isset($_POST[$item]) && strlen($_POST[$item]) > 0) {
      return true;
    }
  }
  return false;
}

// returns true if all of the parameters passed to it are set in the POST environment
function allSet() {
  foreach(func_get_args() as $item) {
    if(!isset($_POST[$item]) || strlen($_POST[$item]) <= 0) {
      return false;
    }
  }
  return true;
}

// echos the value of the given variable in POST if and only if it is set
function e($var) {
  if(isset($_POST[$var])) {
    echo ' value="' . $_POST[$var] . '"';
  }
}

// returns the value of the given variable in POST if it is set and has a length > 0, otherwise returns NULL
function n($name) {
  if(isset($_POST[$name]) && strlen($_POST[$name]) > 0) {
    return $_POST[$name];
  } else {
    return NULL;
  }
}

// returns the value of the given variable in POST if it is set, otherwise returns an empty string
function b($name) {
  if(isset($_POST[$name])) {
    return $_POST[$name];
  } else {
    return "";
  }
}

// returns the value of the given variable in POST if it is set, otherwise returns an empty array
function bArr($name) {
  if(isset($_POST[$name])) {
    return $_POST[$name];
  } else {
    return array();
  }
}

// returns the integer value of the given variable in POST if it is set to a valid integer, otherwise returns 0
function bInt($name) {
  if(isset($_POST[$name])) {
    $i = intval($_POST[$name]);
    return is_nan($i) ? 0 : $i;
  } else {
    return 0;
  }
}

// interprets a checkbox sent via POST
function isChecked($name) {
  return isset($_POST[$name]);
}

// authenticates user. must include get-db.php before using
function authenticate() {
  if(!isset($_COOKIE['hash'])) return -1;
  global $mysql;
  $stmt = $mysql->prepare("SELECT `id` FROM `sessions` WHERE `hash` = ?");
  $stmt->bind_param("s", $_COOKIE['hash']);
  $stmt->execute();
  $id = NULL;
  $stmt->bind_result($id);
  if(!$stmt->fetch()) return -1;
  return $id;
}

// signs the current user in. must include get-db.php before using. returns true on success, false on failure
function signIn($email, $pass) {
  global $mysql;
  $stmt = $mysql->prepare("SELECT `id` FROM `students` WHERE `email` = ? AND `pass` = AES_ENCRYPT(?, 'supersecretkey')");
  $stmt->bind_param("ss", $email, $pass);
  $stmt->execute();
  $id = NULL;
  $stmt->bind_result($id);
  if(!$stmt->fetch()) {
    $stmt->close();
    return false;
  } else {

    $stmt->close();

    $stmt = $mysql->prepare("INSERT INTO `sessions` VALUES(?, ?, NULL)");
    $hash = bin2hex(openssl_random_pseudo_bytes(23));
    $expiration = isChecked("remember") ? time() + (60 * 60 * 24 * 30) : 0;
    $stmt->bind_param("is", $id, $hash);
    $stmt->execute();
    $stmt->close();

    setcookie('hash', $hash, $expiration, "/");

    return true;
  }
}

function handleError($errno, $errstr, $errfile, $errline, array $errcontext) {
  if(error_reporting() === 0) return false;

  throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
}

// returns the lat-long of an address or zip code
function locate($address) {
  set_error_handler('handleError');
  try {
    $url = "http://maps.googleapis.com/maps/api/geocode/xml?sensor=true&address=" . urlencode($address);
    $xml = simplexml_load_file($url);
    if($xml->status == "OK") {
      $loc = $xml->result->geometry->location;
      restore_error_handler();
      return array("lat" => $loc->lat, "long" => $loc->lng);
    }
  } catch (ErrorException $e) {

  }
  restore_error_handler();
  return array("lat" => 0.0, "long" => 0.0);
}

// returns an array of the results of a mysql query as arrays. query should be executed already.
function getResult($stmt) {
  $meta = $stmt->result_metadata(); 
  while ($field = $meta->fetch_field()) { 
      $params[] = &$row[$field->name]; 
  } 

  call_user_func_array(array($stmt, 'bind_result'), $params); 

  while ($stmt->fetch()) { 
      foreach($row as $key => $val) { 
          $c[$key] = $val; 
      } 
      $result[] = $c; 
  }

  return isset($result) ? $result : NULL;
}

// converts results returned by getResult to school objects
function getSchools($results) {
  require_once "Class_School.php";
  foreach($results as $result) {
    $schools[] = new School($result);
  }
  return isset($schools) ? $schools : NULL;
}

// converts results returned by getResult to student objects
function getStudents($results) {
  require_once "Class_Student.php";
  foreach($results as $result) {
    $students[] = new Student($result);
  }
  return isset($students) ? $students : NULL;
}

function formatSchool($school, $listPage=false) {
  global $mysql, $id;
  $format = '<tr id="row-' . $school->id() . '">';

  $format .= '<td><a href="school/' . $school->urlname() . '">' . $school->name() . '</a></td>';
  $format .= '<td>' . $school->city() . '</td>';
  $format .= '<td>' . $school->state() . '</td>';
  $format .= '<td>' . $school->satRange() . '</td>';
  $format .= '<td>' . $school->actRange() . '</td>';
  $format .= '<td>' . $school->acceptanceRate() . '</td>';

  $stmt = $mysql->prepare("SELECT `list_id` FROM `lists` WHERE `student_id` = ? AND `school_id` = ?");
  $stmt->bind_param("ii", $id, $school->id());
  $stmt->execute();
  $listID = NULL;
  $stmt->bind_result($listID);
  $inList = $stmt->fetch();
  if($listPage) {
    $format .= '<td class="save"><a onclick="removeFromList(' . $school->id() . ',' . $listID . ')">Remove</a></td>';
  } else {
    $lists = array(0 => "Reach", 1 => "Target", 2 => "Safety");
    if($inList) {
      $format .= '<td class="save"><span>' . $lists[$listID] . '</span><div class="list-popup"><a onclick="removeFromList(' . $school->id() . ',' . $listID . ')">Remove</a></div></td>';
    } else {
      $format .= '<td class="save"><span>Save &raquo;</span><div class="list-popup"><a onclick="addToList(' . $school->id() . ',0)">Reach</a><a onclick="addToList(' . $school->id() . ',1)">Target</a><a onclick="addToList(' . $school->id() . ',2)">Safety</a></div></td>';
    }
  }
  $stmt->close();

  $format .= '</tr>';

  return $format;
}

function getColor($schoolHas, $schoolMin, $schoolMax, $studentHas, $studentScore) {
  return ($schoolHas && $studentHas) ? ($studentScore < $schoolMin ? '#900' : ($studentScore > $schoolMax ? '#090' : '#990')) : '#000';
}

?>