<?php
 /**
  * SILI Message API
  *
  * Message API contains functions to manage the direct messages between users
  * and functions to interect with the messages table in the database.
  * 
  * Direct access to this file is not allowed, can only be included
  * in files and the file that is including it must contain 
  *	$internal=true;
  *  
  * @copyright 2016 GLADE
  *
  * @author Probably Dan (w/help from Lewis :) )
  *
  */

//Check that only approved methods are trying to access this file (Internal Files/API Controller)
if (!isset($internal) && !isset($controller))
{
	//Trying to direct access
	http_response_code(403);
	exit;
}

/**
 *
 * Generate a random messageID
 *
 * Generates a random messageID checking that it does not 
 * already exist in the database
 * 
 * @return   string The messageID for the Message
 *
 */
function GenerateMessageID()
{
	global $db;
	$messageID = "";

	//Generate MessageID
	do {
	  	$bytes = openssl_random_pseudo_bytes(20, $cstrong);
	   	$hex = bin2hex($bytes);
	   	
		$queryResult = $db->rawQuery("SELECT messageID FROM Message WHERE messageID = ?", Array($hex));
	   	//Check the generated id doesnt already exist
		if (count($queryResult) == 0)
		{
			$messageID = $hex;
		}
	} while ($messageID == "");
	
return $messageID;
}

/**
 *
 * Create record for message
 *
 * @param    int  $profileID of the current logged in user
 * @return   array Containing the message or any errors that have occurred
 *
 */
function MessageIt($profileID)
{
	global $db, $errorCodes, $request;
	// Arrays for jsons
	$result = array();
	$errors = array();

	if ($db->ping() !== TRUE) 
	{
		array_push($errors, $errorCodes["M001"]);
	}

	$recipientProfileID = 0;

	if (count($request) >= 3)
	{
		if (strlen($request[2]) > 0)
		{
			$recipientProfileID = filter_var($request[2], FILTER_SANITIZE_STRING);	
		}
	}
	
	if ($profileID === 0 || $recipientProfileID === 0)
	{
		array_push($errors, $errorCodes["G002"]);
	}
	else
	{
		// Check if the Message has been submitted and is longer than 0 chars
		if ((!isset($_POST['messageBox'])) || (strlen($_POST['messageBox']) == 0))
		{
			array_push($errors, $errorCodes["S003"]);
		}
		else
		{
			$messageContent = htmlspecialchars($_POST['messageBox']);
			$messageID = GenerateMessageID();

			$data = Array(
				"messageID" => $messageID,
				"profileID" => $profileID,
				"recipientProfileID" => $recipientProfileID,
               	"message" => $messageContent,
               	"timeSent" => date("Y-m-d H:i:s")
			);
			$db->insert("Message", $data);

			$message = FetchMessage($messageID, $profileID);			
		}
	}

	// If no errors insert Say message into database
	if (count($errors) == 0)
	{
		$result["message"] = "Message has been added";
		$result["message"] = $message;
		
	}
	else //return the json of errors 
	{	
		$result["message"] = "Message failed";	
		$result["errors"] = $errors;
	}
	
	return $result;
}

/**
 *
 * Return all the messages for the current user
 *
 * Returns all the messages between current user and other users
 *
 * @param    int  $profileID of the current logged in user
 * @return   array Containing the messages or any errors that have occurred
 *
 */
function GetMessages($profileID)
{
	global $db, $errorCodes;
	// Arrays for jsons
	$result = array();
	$messages = array();
	
	if ($db->ping() !== TRUE) 
	{
		array_push($errors, $errorCodes["M001"]);
	}

	$recipientProfileID = 0;

	if (count($request) >= 3)
	{
		if (strlen($request[2]) > 0)
		{
			$recipientProfileID = filter_var($request[2], FILTER_SANITIZE_STRING);	
		}
	}
	
	if ($profileID === 0 || $recipientProfileID === 0)
	{
		array_push($errors, $errorCodes["G002"]);
	}
	else
	{
		$queryResult = $db->rawQuery("SELECT firstName, lastName, userName, profileImage FROM Profile WHERE profileID = ?", Array($recipientProfileID));

		if (count($queryResult) == 1)
		{
			$firstName = $queryResult[0]["firstName"];
			$lastName = $queryResult[0]["lastName"];
			$userName = $queryResult[0]["userName"];
			$profileImage = $queryResult[0]["profileImage"];
				
							
			if ($profileImage == "")
			{
				$profileImage = $defaultProfileImg;
			}
			
			$messageProfile = [
			"firstName" => $firstName,
			"lastName" => $lastName,
			"userName" => $userName,
			"profileImage" => $profileImagePath . $profileImage,
			];
		}

		$messagesQuery = "SELECT messageID FROM Message WHERE profileID = ? AND recipientProfileID = ? ORDER BY timeSent DESC";

		$queryResult = $db->rawQuery($messagesQuery, Array($profileID, $recipientProfileID));
		if (count($queryResult) >= 1)
		{
			foreach ($queryResult as $value) {
				$messageID = $value["messageID"];
				array_push($messages, FetchMessage($messageID, $profileID));
			}
		}	

		$result["recipientProfile"] = $messageProfile;
		$result["messages"] = $messages;
	}
	return $result;
}

/**
 *
 * Return most recent message from each conversation between 2 users
 *
 * For display on Messages page
 *
 * @param    int  $profileID of the current logged in user
 * @return   array Containing the conversations or any errors that have occurred
 *
 */
function GetConversation($profileID)
{
	global $db, $errorCodes;
	// Arrays for jsons
	$result = array();
	$messages = array();

	if ($db->ping() !== TRUE) 
	{
		array_push($errors, $errorCodes["M001"]);
	}

	if ($profileID === 0)
	{
		array_push($errors, $errorCodes["G002"]);
	}
	else
	{
		$messagesQuery = "SELECT messageID FROM Message WHERE Message.profileID = ? GROUP BY recipientProfileID ORDER BY timeSent ASC ";

		$queryResult = $db->rawQuery($messagesQuery, Array($profileID));
		if (count($queryResult) >= 1)
		{
			foreach ($queryResult as $value) {
				$messageID = $value["messageID"];
				array_push($messages, FetchMessage($messageID, $profileID));
			}
		}	

		$result["messages"] = $messages;
	}
	return $result;
}

/**
 *
 * Return messages requested by the above functions, GetConversation(), GetMessages(), and MessageIt()
 * 
 *
 * @param    int  $messageID of the requested message
 * @return   array Containing the message requested
 *
 */
function FetchMessage($messageID, $profileID)
{
	global $db, $errorCodes;
	// Arrays for jsons
	$result = array();
	$messages = array();

	$ownMessage = false;

	if ($db->ping() !== TRUE) 
	{
		array_push($errors, $errorCodes["M001"]);
	}

	if ($profileID === 0)
	{
		array_push($errors, $errorCodes["G002"]);
	}
	else
	{
		$messagesQuery = "SELECT profileID, message, timeSent FROM Message WHERE messageID = ? ORDER BY timeSent ASC ";

		$queryResult = $db->rawQuery($messagesQuery, Array($messageID));

		$senderProfileID = $queryResult[0]["profileID"];
		$message = $queryResult[0]["message"];
		$timeSent = $queryResult[0]["timeSent"];
		
		if ($profileID == $senderProfileID)
		{
			$ownMessage = true;
		}

		$message = [
		"ownMessage" => $ownMessage,
		"messageID" => $messageID,
		"message" => $message,
		"timeSent" => strtotime($timeSent) * 1000,
		];
	}

	return $message;
}


?>