<?php
////////////////////////////////////////////////////////////////////////////
// COURSE SCRAPER
//
// @author	Ben Russell (benrr101@csh.rit.edu)
//
// @file	tools/scrape.php
// @descrip	A stand alone tool for scraping the nightly course dump and then
//			scraping the register.rit.edu pages for further details not
//			provided in the dump. This script is crucial, and if the RIT
//			site/course structure is changed this script will probably have
//			to be rewritten
////////////////////////////////////////////////////////////////////////////

// REQUIRED FILES //////////////////////////////////////////////////////////
require_once "../inc/config.php";
require_once "../inc/databaseConn.php";
require_once "../inc/timeFunctions.php";
require_once "../inc/httphelper.php";

// CMD LINE ARGUMENTS //////////////////////////////////////////////////////
$arguments = $_SERVER['argv'];
$debugMode = in_array("-d", $arguments);
$quietMode = in_array("-q", $arguments);

// FUNCTIONS ///////////////////////////////////////////////////////////////
function debug($string) {
	global $debugMode;
	if($debugMode) {
		echo($string);
	}
}

// MAIN EXECUTION //////////////////////////////////////////////////////////
// Open up the dump file
$dumpHandle = fopen($DUMPLOCATION, "r");
if(!$dumpHandle) {
	die("*** Could not open a handle to the dump file ({$DUMPLOCATION})\n");
}

// Create a handle for getting course lists
$handle = new courseListHandle();

// Variable to avoid doing extra queries on the quarters and courses
$curQuarter = "";
$curDepartment = "";
$curDepartmentCourseList = "";
$curCourse  = "";
$courseId   = 0;
// Read off all the courses
while($line = fgets($dumpHandle, 4096)) {
	$lineSplit = explode('|', $line);

	// Grab the quarter number
	$quarter = $lineSplit[0];

	// Have we already looked at this quarter?
	if($curQuarter != $quarter) {
		debug("... Processing Quarter: {$quarter}\n");
		// Nope. Insert the quarter if it doesn't already exist
		$curQuarter = $quarter;

		// Sanitize the quarter
		$quarter = mysql_real_escape_string($quarter);

		// Get and sanitize the start and end dates of the quarter
		$qStart = mysql_real_escape_string($lineSplit[3]);
		$qEnd   = mysql_real_escape_string($lineSplit[4]);
		
		$query = "INSERT INTO quarters (quarter, start, end) VALUES({$quarter}, {$qStart}, {$qEnd}) ";
		$query .= "ON DUPLICATE KEY UPDATE start={$qStart}, end={$qEnd}";
		$result = mysql_query($query);
		if(!$result) {
			echo("*** Could not add quarter: {$quarter}\n" . mysql_error() . "\n");
		}
		
		// Set the quarter on the course list handle
		$handle->setQuarter($quarter);
	}

	// Determine the course numer of this line	
	$department = substr($lineSplit[1], 0, 4);
	$course     = substr($lineSplit[1], 4, 3);
	$section    = substr($lineSplit[1], -2);
	$courseNum  = $department . $course;
	
	// Have we already looked at this department?
	if($curDepartment != $department) {
		// Go download the latest course list
		$curDepartmentCourseList = $handle->getCourseList($department);
		$curDepartment = $department;
	} 

	// Have we already looked at this course?
	if($curCourse != $courseNum) {
		debug("   ... Processing Course: {$department}-{$course}\n");
		// Nope. Insert the course if it doesn't already exist
		$curCourse = $courseNum;
		
		$credits = $lineSplit[11];	// MAX-CREDIT
		
		// OK, since the dump from RIT is absolutely fucking retarded, we have
		// to do an old school scrape.
		// FUCK ITS.
		$coursePage = file_get_contents("https://register.rit.edu/courseSchedule/{$department}{$course}");
		if(!$coursePage) {
			echo("*** Could not load https://register.rit.edu/courseSchedule/{$department}{$course}\n");
			continue;
		}
		
		// Now let's run some regexps on it to get down to the good stuff
		$pattern = "/<strong>Course Title: <\/strong>.*<td.*>(.*)<\/td>.*<strong>Description:<\/strong>.*<td.*>(.*)<\/td>/msU";
		$matches = array();
		if(preg_match($pattern, $coursePage, $matches) != 1) {
			echo("*** Could not match the regexp for course title and description! https://register.rit.edu/courseSchedule/{$department}{$course}\n");
			continue;
		}
		$title       = mysql_real_escape_string($matches[1]);
		$description = mysql_real_escape_string($matches[2]);
		
		// Build a query to insert the course
		$query = "INSERT INTO courses (department, course, credits, quarter, title, description) ";
		$query .= "VALUES ({$department}, {$course}, {$credits}, {$quarter}, '{$title}', '{$description}') ";
		$query .= "ON DUPLICATE KEY UPDATE credits={$credits}, title='{$title}', description='{$description}' ";
		$result = mysql_query($query);
		if(!$result) {
			echo("*** Could not add the course {$department}-{$course}\n" . mysql_error() . "\n");
			continue;
		}

		// Query real quick for the course Id
		$query = "SELECT id FROM courses ";
		$query .= "WHERE quarter = {$quarter} AND course = {$course} AND department = {$department}";
		$result = mysql_query($query);
		if(!$result || mysql_num_rows($result) != 1) {
			echo("*** Failed to lookup course after insert/update\n{$query}\n" . mysql_error() . "\n");
			continue;
		}

		$courseId = mysql_fetch_assoc($result);
		$courseId = $courseId['id'];
	}
	
	// The courseID is preserved between iterations in this loop.
	// What's left in the line is information on the section
	$sectionTitle = mysql_real_escape_string(ucfirst(strtolower($lineSplit[2])));
	$instructor = mysql_real_escape_string(ucfirst(strtolower($lineSplit[22])) . ' ' . ucfirst(strtolower($lineSplit[23])));
	$maxEnroll  = mysql_real_escape_string($lineSplit[13]);
	$curEnroll  = mysql_real_escape_string($lineSplit[14]);
	$status     = mysql_real_escape_string($lineSplit[6]);
	if($lineSplit[15] == "Y") { $type = "O"; }
	elseif($lineSplit[17] == "Y") { $type = "H"; }
	elseif($lineSplit[16] == "Y") { $type = "N"; }
	else { $type = "R"; }

	// Does this section already exist
	$query = "SELECT id FROM sections WHERE course = {$courseId} AND section = {$section}";
	$result = mysql_query($query);
	if(!$result) {
		echo("*** Failed attempting to lookup section\n" . mysql_error() . "\n");
		continue;
	}
	if(mysql_num_rows($result)) {
		// The section already exists, so we need to update it
		$sectionId = mysql_fetch_assoc($result);
		$sectionId = $sectionId['id'];

		debug("      ... Updating Section: {$department}-{$course}-{$section}\n");
		
		// Build the query for updating the section
		$query = "UPDATE sections SET";
		$query .= " instructor = '{$instructor}',";
		$query .= " status = '{$status}',";
		$query .= " type = '{$type}',";
	
		if($curDepartmentCourseList != NULL && isset($curDepartmentList[$course . $section])) {
			// Steal course title and enrollment from the latest scrape of SIS
			$query .= " title = '" . mysql_real_escape_string($curDepartmentCourseList[$course . $section]['title']) . "',";
			$query .= " maxenroll='" . mysql_real_escape_string($curDepartmentCourseList[$course . $section]['maxEnroll']) . "',";
			$query .= " curenroll='" . mysql_real_escape_string($curDepartmentCourseList[$course . $section]['curEnroll']) . "'";
		} else {
			// Nope, we had to use the course dump
			if(!empty($sectionTitle)) {
				$query .= " title = '{$sectionTitle}',";
			}
			$query .= " maxenroll='{$maxEnroll}',";
			$query .= " curenroll='{$curEnroll}'";
		}
		$query .= " WHERE id = {$sectionId}";
		$result = mysql_query($query);
		if(!$result) {
			echo("*** Failed to update section\n" . mysql_error() . "\n");
			continue;
		}
	} else {
		// The section does not exist, so it needs to be inserted
		debug("      ... Inserting Section: {$department}-{$course}-{$section}\n");
		
		$query = "INSERT INTO sections (course, section, status, instructor, type, maxenroll, curenroll, title) ";
		$query .= "VALUES (";
		$query .= "{$courseId}, ";
		$query .= "{$section}, ";
		$query .= "'{$status}', ";
		$query .= "'{$instructor}', ";
		$query .= "'{$type}', ";
		if($curDepartmentCourseList != NULL && isset($curDepartmentCourseList[$course . $section])) {
			// Steal course title and enrollment from the latest scrape of SIS
			$query .= "{$curDepartmentCourseList[$course . $section]['maxEnroll']}, ";
			$query .= "{$curDepartmentCourseList[$course . $section]['curEnroll']}, ";
			$query .= "{$curDepartmentCourseList[$course . $section]['title']}";
		} else {
			// Nope, we're gonna use the course dump
			$query .= "{$maxEnroll}, ";
			$query .= "{$curEnroll}, ";
			$query .= "{$title}";
		}
		$query .= ")";

		$result = mysql_query($query);
		if(!$result) {
			echo("*** Failed to insert section\n" . mysql_error() . "\n");
			continue;
		}
		$sectionId = mysql_insert_id();
	}

	// Now for the fun part: times
	// First step is to delete all the times that the section currently has	
	$query = "DELETE FROM times WHERE section = {$sectionId}";
	$result = mysql_query($query);
	if(!$result) {
		echo("*** Failed to delete old section's times\n" . mysql_error() . "\n");
		continue;
	}
	
	// Next, we'll check each time slot from the dump and see if we need to
	// insert a row
	$times = array();
	if(!empty($lineSplit[28])) {
		// Split it by , to get each piece of information
		$timeSplit = explode(',', $lineSplit[28]);
		
		// Process each time (there are 5 fields per time)
		for($i = 0; $i < count($timeSplit); $i += 5) {
			$day   = mysql_real_escape_string($timeSplit[$i]);
			$start = mysql_real_escape_string(translateTimeDump($timeSplit[$i+1]));
			$end   = mysql_real_escape_string(translateTimeDump($timeSplit[$i+2]));
			$bldg  = mysql_real_escape_string($timeSplit[$i+3]);
			$room  = mysql_real_escape_string($timeSplit[$i+4]);

			if(!is_numeric($day)) { continue; }

			$times[] = "({$sectionId}, {$day}, {$start}, {$end}, '{$bldg}', '{$room}')";
		}

		// Bring the query together
		if(count($times)) {
			$query = "INSERT INTO times (section, day, start, end, building, room) VALUES ";
			$query .= implode(', ', $times);
			
			$result = mysql_query($query);
			
			if(!$result || mysql_affected_rows() == 0) {
				echo("*** Could not add times for section! " . mysql_error() . "\n");
				continue;
			}
		}
	}
}
