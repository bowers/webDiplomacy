<?php
/*
    Copyright (C) 2004-2010 Kestas J. Kuliukas

	This file is part of webDiplomacy.

    webDiplomacy is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    webDiplomacy is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with webDiplomacy.  If not, see <http://www.gnu.org/licenses/>.
 */

/**
 * @package GameMaster
 */

require_once('header.php');

require_once(l_r('gamemaster/game.php'));
require_once(l_r('gamemaster/misc.php'));

if ( $Misc->Panic )
{
	libHTML::notice(l_t('Game processing disabled'),
		l_t("Game processing has been temporarily disabled while we take care of an ".
		"unexpected problem. Please try again later, sorry for the inconvenience."));
}

if ( !( $User->type['Moderator']
	or ( isset($_REQUEST['gameMasterSecret']) and $_REQUEST['gameMasterSecret'] == Config::$gameMasterSecret )
	or ( isset($_REQUEST['gameMasterToken']) and libAuth::gamemasterToken_Valid($_REQUEST['gameMasterToken']) )
	) )
{
	libHTML::notice(l_t('Denied'), l_t('Only the cron script and moderators can run the gamemaster script.'));
}

if ( isset($_REQUEST['gameMasterSecret']) && $User->type['User'] && !$User->type['Moderator'] && $Misc->LastProcessTime == 0 )
{
	// The server has just been installed; make this user the admin now.
	$DB->sql_put("UPDATE wD_Users SET type = CONCAT(type,',Moderator,Admin') WHERE id = ".$User->id);
	$User->type['Moderator']=$User->type['Admin']=true;
	$Misc->LastProcessTime = time();
	$Misc->write();
	libHTML::notice(l_t('Admin'),l_t("You have been made admin. Please continue with the install instructions in README.txt."));
}

libHTML::starthtml(l_t('GameMaster'));

print '<div class="content">';

$DB->sql_put("COMMIT"); // Unlock our user row, to prevent deadlocks below
// This means our $User object should only be used for reading from

ini_set('memory_limit',"40M");
ini_set('max_execution_time','40');


/*
 * - Update session table
 * - Update misc values (if running as admin/mod)
 * - Check last process time, pause processing/save current process time
 * - Check queue and games table for games to process, votes to enact, and system functions to perform
 */
print l_t('Updating session table').'<br />';
libGameMaster::updateSessionTable();

$statsDir=libCache::dirName('stats');
// $onlineFile=$statsDir.'/onlineUsers.json';
// $tabl=$DB->sql_tabl("SELECT userID FROM wD_Sessions");
// $onlineUsers=array();
// while(list($userID)=$DB->tabl_row($tabl))
// 	$onlineUsers[]=$userID;

// file_put_contents($onlineFile, 'onlineUsers=$A(['.implode(',',$onlineUsers).']);');

//- Update misc values (if running as admin/mod)
if( !$User->type['System'] || (time()%(15*60)<=5*60) )
{
	print l_t('Updating Misc values').'<br />';
	miscUpdate::errorLog();
	miscUpdate::forum();
	miscUpdate::game();
	miscUpdate::user();
}

//- Check last process time, pause processing/save current process time
if ( ( time() - $Misc->LastProcessTime ) > Config::$downtimeTriggerMinutes*60 )
{
	libHTML::notice(l_t('Games not processing'),libHTML::admincp('resetLastProcessTime',null,l_t('Continue processing now')));
}

// Get the current processing time. It is important to save this at this point so that next process the next 
// LastProcessTime will exactly match this process' $currentProcessTime (this ensures all turns that pass over 1 year
// old get processed properly .. unless the last process time gets reset, in which case the turn counts need to be
// recalculated)
$currentProcessTime = time();

// Before the LastProcessTime is overwritten with the current time decrement any turns older than a year from players' phase counts
// Temporarily disabled, still causing deadlocks:
//$DB->sql_put("UPDATE wD_Users u INNER JOIN ( SELECT userID, COUNT(*) turns FROM wD_TurnDate WHERE (".$Misc->LastProcessTime." - 365*24*60*60) <= turnDateTime AND turnDateTime < (".$currentProcessTime." - 365*24*60*60) GROUP BY userID ) expiredTurns ON expiredTurns.userID = u.id SET u.yearlyPhaseCount = u.yearlyPhaseCount - expiredTurns.turns");

$Misc->LastProcessTime = $currentProcessTime;
$Misc->write();


$startTime = $currentProcessTime; // Only do ~30 sec of processing per cycle
$tabl = $DB->sql_tabl("SELECT * FROM wD_Games
	WHERE processStatus='Not-processing' AND processTime <= ".time()." AND NOT phase='Finished'");

while( (time() - $startTime)<30 && $gameRow=$DB->tabl_hash($tabl) )
{
	$Variant=libVariant::loadFromVariantID($gameRow['variantID']);
	$Game=$Variant->Game($gameRow);

	print '<a href="board.php?gameID='.$Game->id.'">gameID='.$Game->id.': '.$Game->name.'</a>: ';

	try
	{
		if( $Game->processStatus!='Crashed' && $Game->attempts > count($Game->Members->ByID)*2 )
		{
			$Game = $Variant->processGame($Game->id);
			$Game->crashed();
			$DB->sql_put("COMMIT");
			print 'Crashed.';
		}
		elseif( $Game->needsProcess() )
		{
			$DB->sql_put("UPDATE wD_Games SET attempts=attempts+1 WHERE id=".$Game->id);
			$DB->sql_put("COMMIT");
			print 'Rechecking.. ';

			$Game = $Variant->processGame($Game->id);
			if( $Game->needsProcess() )
			{
				print l_t('Processing..').' ';
				$Game->process();
				$DB->sql_put("UPDATE wD_Games SET attempts=0 WHERE id=".$Game->id);
				$DB->sql_put("COMMIT");
				print l_t('Processed.');
			}
		}
	}
	catch(Exception $e)
	{
		if( $e->getMessage() == "Abandoned" || $e->getMessage() == "Cancelled" )
		{
			$DB->sql_put("COMMIT");
			print l_t('Abandoned.');
		}
		else
		{
			$DB->sql_put("ROLLBACK");
			print l_t('Crashed: "%s".',$e->getMessage());
		}
	}

	print '<br />';
}

// Find any turns which have just passed more than one year old, and 
// If it took over 30 secs there may still be games to process
if( (time() - $startTime)>=30 )
{
	/*
	 * For when you're developing and just reloaded the DB from a backup,
	 * you usually have to refresh a few times before it runs out of games
	 * to process
	 */
	header('refresh: 4; url=gamemaster.php');
	print '<p class="notice">'.l_t('Timed-out; re-running').'</p>';
}
else
{
	// Finished all remaining games with time to spare; update the civil disorder and NMR counts
	//libGameMaster::updateCDNMRCounts();
}

print '</div>';
libHTML::footer();

?>
