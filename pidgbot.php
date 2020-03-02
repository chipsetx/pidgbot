<?php

/*
	pidgbot
	Copyright (C) 2020 Taras Young
	
	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.
	
	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
	
	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 
	02110-1301, USA.

*/

/* = Settings ================ */
  $host = "irc.example.org";			// Server address
  $port = 6667;					// Port
  $nick = "pidgbot";				// Nick
  $pass = "";					// Server password (NOT NickServ password)
  $user = "pidgbot";				// Real name
  $usermode = "";				// Any usermodes to set/unset on connection e.g. -x
  $channels = array("#lobby");			// Array containing channels to auto-join on connection
  $owners = array("bob!*@*example.org");	// Array containing hostmasks of owners (use wildcards * ?) e.g. bob!*@example.org
  $verbose = 1;					// Whether all text received is output to the console
  $quitmsg = "Goodbye";				// Message to send on quit
/* ========================== */

/* ---------------------------------------------------------------------------------------------------------- */

function debug($output, $incoming=0)
{ 
	// Outputs what's happening to the console

	$output = trim($output);
	if ( $output )
	{
		$symbol = array("<-", "->", "--", "<>", "!!", ":)");
		echo ($incoming)? $symbol[$incoming] : $symbol[0];
		echo " $output\n"; 
	}
}

function string_matches($string, $pattern, $ignoreCase = FALSE)
{
	// glob-style wildcard matching (*?)

	$match = preg_replace_callback('/[\\\\^$.[\\]|()?*+{}\\-\\/]/', function($matches)
	{
		switch ($matches[0])
		{
			case '*': return '.*';
			case '?': return '.';
			default: return '\\'.$matches[0];
		}
	}, $pattern);

	$match = '/' . $match . '/';
	if ($ignoreCase) {
		$match .= 'i';
	}

	return (bool) preg_match($match, $string);
}

function starts_with($haystack, $needle)
{
	// Check if $haystack starts with $needle and return remainder if true

	if ( substr($haystack, 0, strlen($needle)) == $needle )
	{
		return substr($haystack, strlen($needle));
	} else {
		return NULL;
	}
}

function irc_connect($host, $port=6667, $nick="pidgbot", $pass="", $user="pidgbot")
{
	// Connects to an irc server

	debug ( "pidgbot by @pidg - an irc client/bot written in php", 5 );
	debug ( "This software is licensed under the GNU General Public License v2 (or higher).", 5);
	echo "\n";

	debug ("Connecting to $host:$port ...", 2);

	$socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);		// Create socket

	socket_connect($socket, $host, $port);				// Connect to remote host
	socket_set_nonblock($socket);					// Set socket to non-blocking

	irc_do($socket, "NICK $nick");					// Set nick
	irc_do($socket, "USER $nick 0 * :$user");			// Set username

	return $socket;
}

function irc_do($socket, $command, $log=1)
{
	// Sends a command to the server

	if ( $log ) debug ("$command");
	socket_write($socket, "$command\n");
}

function irc_action($socket, $to_whom, $text)
{
	// Sends a PRIVMSG ACTION e.g. * pidgbot slaps zuzak with a large trout
	// Added as a separate function as it's a pain to get the format right.

	irc_do ( $socket, "PRIVMSG $to_whom :" . chr(1) . "ACTION $text" . chr(1) );
}

function get_nick($input,$part=0)
{
	// Extracts part of a hostmask like taras!taras@example.org
	// Defaults to nick

	if ( stristr($input, "!") )
	{
		$nick = explode("!", $input, 2)[$part];
	} else {
		$nick = 0;	// Not a valid hostmask
	}

	return $nick;
}

function is_owner($owners, $hostmask)
{
	// Checks a nick/hostmask against the bot's owners

	$flag=0;
	foreach ( $owners as $owner ) if ( string_matches($hostmask, $owner) ) $flag = 1;

	return $flag;
}


/* ---------------------------------------------------------------------------------------------------------- */

// Let's go!

$start_time = time();

set_time_limit(0);	// Tell PHP not to time out
ob_implicit_flush();	// Flush implicitly

$socket = irc_connect($host, $port, $nick, $pass, $user);	// Connect to irc server
sleep(2);							// Wait patiently

if ( $usermode ) irc_do($socket, "MODE $nick $usermode");	// Set any user modes

// Join channel(s)
if ( isset($channels) ) foreach($channels as $chan) irc_do($socket, "JOIN $chan" );

// Main loop

$last_time = time();

while(1)
{
	// The timeout controls how often the main loop loops. If all your
	// events are triggered by user interaction then you really should
	// set it higher than 1 second to reduce CPU impact.
	$timeout = 1;

	$sockread = array($socket);
	$sockwrite = NULL; $sockexcept = NULL;
	$changed = socket_select($sockread, $sockwrite, $sockexcept, $timeout);				// Check for changes to socket

	if ( $changed )
	{
		$in = trim(socket_read($socket, 2048, PHP_NORMAL_READ));				// Read data from socket

		if ( $in )
		{
			$in = (substr($in, 0, 1) == ":")? substr($in, 1):$in;				// Strip initial : prefix if present
			if (stristr($in, ":")) $content = trim(explode(":", $in, 2)[1]);		// Extract content after first : delimeter

			if ( $verbose ) debug($in, 1);							// Log all incoming text to console

			$e = explode(" ", $in);

			if ( isset($e[0]) ) if ( $e[0] == "PING" )
			{
				// Respond to server pings to keep connection alive
				irc_do ($socket, "PONG " . $e[1], 0);
				debug("PING? PONG!", 3);
			}


			if ( isset($e[1]) )
			{
				if ( (intval($e[1]) >= 1 && intval($e[1]) <= 3) || (intval($e[1]) >= 370 && intval($e[1]) <= 380) )
				{
					// Shows welcome message and MOTD
					debug($content, 1);
				}
				

				if ( $e[1] == "NOTICE" )
				{ 
					// Handle incoming NOTICEs
					
					if ( !stristr($e[0], "!" ) )
					{
						// Notice from server
						debug($content, 4);
	
					} else {

						$hostmask = $e[0];
						$user_nick = get_nick($hostmask);
						$user_host = get_nick($hostmask, 1);
						$to_whom = $e[2];

						// Notice from user (including services)
						if ( $to_whom != $nick )
						{
							debug("-$user_nick:$to_whom- $content", 4);	// Wide notice
						} else {
							debug("-$user_nick($user_host)- $content", 4);	// Narrow notice
						}
					}

				}

				if ( $e[1] == "PRIVMSG" )
				{ 
					// Handle messages
					
					if ( stristr($e[0], "!" ) )		// Ensure it's actually from someone
					{
						$hostmask = $e[0];
						$to_whom = $e[2];
						$user_nick = get_nick($hostmask);
						$user_host = get_nick($hostmask, 1);

						$owned = is_owner($owners, $hostmask);	// Check whether user is an owner of this bot
						
						if ( $to_whom != $nick )
						{
							// Channel message
							debug("<$user_nick:$to_whom> $content", 1);
		
							$reply_to = $to_whom;	// Send any replies to the channel

						} else {
							// Private message
							debug("<$user_nick> $content", 1);	

							$reply_to = $user_nick;	// Send any replies directly to user
						}

						if ( $content )
						{
							// Custom commands

							if ( substr($content, 0, 1) == "!" )
							{

								if ( $content == "!uptime" )
								{
									// Returns current uptime of bot
									$s = time() - $start_time;
									irc_do ( $socket, "PRIVMSG $reply_to :Current uptime is " . date("H:i:s", $s) );
								}

								if ( ($params = starts_with($content, "!join ")) && $owned )
								{
									// Tells the bot to join a channel
									irc_do ( $socket, "JOIN :$params" );
								}

								if ( ($params = starts_with($content, "!part ")) && $owned )
								{
									// Tells the bot to leave a channel
									irc_do ( $socket, "PART :$params" );
								}

								if ( ($content == "!die") && $owned )
								{
									// Quit and die
									irc_do ( $socket, "QUIT :$quitmsg" );
									debug ( "Terminating. Bye!", 5 );
									die();
								}


							} // (if it's a !command)
						
						} // if ($content)


					} // (if PRIVMSG is from a user)

				} // (if it's a PRIVMSG)

			} // (if there's a command)

		} // (if there is content)

	} // (if socket changed)


	if ( time() != $last_time )
	{
		// Checking that the time has changed means this part is only run
		// when the socket timeout is reached. Without this protection you
		// will be immediately flooded off the server.
		$last_time = time();

		// Anything you need to do that's not triggered by user interaction,
		// i.e. timed or random events, can go here:


	}


} // (main loop)

