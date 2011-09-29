<?php
//  Copyright (c) 2010, LoveMachine Inc.  
//  All Rights Reserved.  
//  http://www.lovemachineinc.com

include("config.php");
include("class.session_handler.php");
include("functions.php");

$req =  isset($_REQUEST['req'])? $_REQUEST['req'] : 'table';

	if( $req == 'currentlink' )	{
		$query_b = mysql_query( "SELECT status FROM ".WORKLIST." WHERE status = 'BIDDING'" );
		$query_w = mysql_query( "SELECT status FROM ".WORKLIST." WHERE status = 'WORKING' or status = 'REVIEW' or status = 'FUNCTIONAL'" );
		$count_b = mysql_num_rows( $query_b );
		$count_w = mysql_num_rows( $query_w );
        echo json_encode(array(
                            'count_b' => $count_b, 
                            'count_w' => $count_w
                            ));
		
	}	else if( $req == 'bidding' )	{
		$query_b = mysql_query("SELECT id FROM ".WORKLIST." WHERE status = 'bidding'");
		$results_b = array();
        while ($row = mysql_fetch_array($query_b, MYSQL_NUM)) {
    		$results_b[] = $row[0];
        }
		echo json_encode( $results_b );

	}	else if( $req == 'current' )	{
		$query_b = mysql_query("SELECT status FROM ".WORKLIST." WHERE status = 'bidding'");
		$query_w = mysql_query("SELECT status FROM ".WORKLIST." WHERE status = 'working'");
		$count_b = mysql_num_rows( $query_b );
		$count_w = mysql_num_rows( $query_w );
		$res = array( $count_b, $count_w );
		echo json_encode( $res );
	
	}	else	if( $req == 'fees' )	{
		// Get Average Fees in last 7 days
		$query = mysql_query( "SELECT AVG(amount) FROM ".FEES." LEFT JOIN ".WORKLIST." ON
					".FEES.".worklist_id = ".WORKLIST.".id WHERE date > DATE_SUB(NOW(),
					INTERVAL 7 DAY) AND status = 'DONE' AND `" . FEES . "`.`withdrawn` = 0" );

		$rt = mysql_fetch_assoc( $query );
		echo json_encode( $rt );
	
	} else if( $req == 'feeslist' )	{
		// Get Fees by person in last X days
		$interval = !empty($_REQUEST['interval']) ? intval($_REQUEST['interval']) : 30;
		$query = mysql_query("SELECT nickname, SUM(amount) as total FROM ".FEES." ".
					"LEFT JOIN ".WORKLIST." ON ".FEES.".worklist_id = ".WORKLIST.".id ".
					"LEFT JOIN ".USERS." ON ".FEES.".user_id = ".USERS.".id ".
					"WHERE date >= DATE_SUB(NOW(), INTERVAL $interval DAY) AND status = 'DONE' AND `" . FEES . "`.`withdrawn` = 0 ".
                    "GROUP BY user_id ORDER BY total DESC");

		$tmpList = array();
        $feeList = array();
		while ($query && ($rt = mysql_fetch_assoc($query))) {
			$tmpList[] = array($rt['nickname'], $rt['total']);
		}

        $total = 0;
		for ($i = 0; $i < count($tmpList); $i++) {
			$total += $tmpList[$i][1];
		}
		$top10 = 0;
		for ($i = 0; $i < 10 && $i < count($tmpList); $i++) {
			$top10 += $tmpList[$i][1];
			$feeList[$i] = $tmpList[$i];
			$feeList[$i][2] = number_format($tmpList[$i][1] * 100 / $total, 2);
		}
		if (count($tmpList) > 10) {
			$feeList[10] = array('Other', number_format($total - $top10, 2), number_format(($total - $top10) * 100 / $total, 2));
        }
		echo json_encode($feeList);

	} else if( $req == 'table' )	{
		// Get jobs done in last 7 days
		$fees_q = mysql_query( "SELECT `".WORKLIST."`.`id`,`summary`,`nickname` as nick,
					  (SELECT SUM(`amount`) FROM `".FEES."`
					   LEFT JOIN `".BIDS."` ON `".FEES."`.`bid_id`=`".BIDS."`.id
					   LEFT JOIN `".USERS."` ON `".USERS."`.`id`=`".FEES."`.`user_id`
					   WHERE `".BIDS."`.`worklist_id`=`".WORKLIST."`.`id`
					   AND `".USERS."`.`nickname`=`nick`) AS total,
					TIMESTAMPDIFF(SECOND,`bid_done`,NOW()) as `delta`,`user_paid`
					FROM `".BIDS."`
					LEFT JOIN `".USERS."` ON `".BIDS."`.`bidder_id` = `".USERS."`.`id` LEFT JOIN `".WORKLIST."`
					ON `".BIDS."`.`worklist_id` = `".WORKLIST."`.`id`
					LEFT JOIN `".FEES."` ON `".FEES."`.`bid_id`=`".BIDS."`.`id`
					WHERE `status`='DONE'
					AND `bid_done` > DATE_SUB(NOW(), INTERVAL 7 DAY)
					AND `accepted`='1'
					ORDER BY `delta` ASC;" );
		$fees = array();
		// Prepare json
		while( $row = mysql_fetch_assoc( $fees_q ) )	{
			$fees[] = array( $row['id'], $row['summary'], $row['nick'], $row['total'], $row['delta'], $row['user_paid'] );
		}

		echo json_encode( $fees );
		
	} else if( $req == 'runners' )	{
		// Get Top 10 runners
		$info_q = mysql_query( "SELECT nickname AS nick, (SELECT COUNT(*) FROM ".FEES." LEFT JOIN ".USERS." ON
					".USERS.".id = ".FEES.".user_id WHERE ".USERS.".nickname=nick AND
					".USERS.".is_runner=1) AS fee_no, (SELECT COUNT(*) FROM ".FEES." LEFT JOIN
					".USERS." ON ".USERS.".id=".FEES.".user_id LEFT JOIN ".WORKLIST." ON
					".WORKLIST.".id=".FEES.".worklist_id WHERE ".WORKLIST.".status='WORKING'
					AND ".USERS.".nickname=nick) AS working_no FROM ".USERS." ORDER BY fee_no DESC" );

		$info = array();
		// Get user nicknames
		while( $row = mysql_fetch_assoc( $info_q ) )	{
			if( count( $info ) < 10 )	{
				if( !empty( $row['nick'] ) )	{
					$info[] = array( $row['nick'],$row['fee_no'],$row['working_no'] );
				}
			}
		}
		echo json_encode( $info );
	
	}	else if( $req == 'mechanics' )	{
		// Get Top 10 mechanics
		$info_q = mysql_query( "SELECT nickname AS nick, (SELECT COUNT(*) FROM ".BIDS." LEFT JOIN ".USERS." ON
					".USERS.".id = ".BIDS.".bidder_id WHERE ".USERS.".nickname=nick
					AND `".BIDS."`.`accepted`='1') AS bid_no,
					(SELECT COUNT(*) FROM ".WORKLIST." LEFT JOIN ".USERS." ON 
					".WORKLIST.".mechanic_id=".USERS.".id WHERE ".USERS.".nickname=nick AND
					".WORKLIST.".status='WORKING') AS work_no FROM ".USERS." ORDER BY work_no DESC" );

		$info = array();
		// Get user nicknames
		while( $row = mysql_fetch_assoc( $info_q ) )	{
			if( count( $info ) < 10 )	{
				if( !empty( $row['nick'] ) )	{
					$info[] = array( $row['nick'],$row['bid_no'],$row['work_no'] );
				}
			}
		}
		echo json_encode( $info );
	
	}	else if( $req == 'feeadders' )	{
		// Get the top 10 fee adders
		$info_q = mysql_query( "SELECT nickname AS nick,(SELECT COUNT(*) FROM ".FEES." LEFT JOIN ".USERS." ON
					".USERS.".id = ".FEES.".user_id WHERE ".USERS.".nickname=nick) AS fee_no,
					(SELECT AVG(amount) FROM ".FEES." LEFT JOIN ".USERS." ON
					".USERS.".id=".FEES.".user_id WHERE ".USERS.".nickname=nick) AS amount
					FROM ".USERS." ORDER BY fee_no DESC" );

		$info = array();
		while( $row = mysql_fetch_assoc( $info_q ) )	{
			if( count( $info ) < 10 )	{
				if( !empty( $row['nick'] ) )	{
					$info[] = array( $row['nick'],$row['fee_no'],$row['amount'] );
				}
			}
		}
		echo json_encode( $info );

	}	else if( $req == 'pastdue' )	{
		// Get the top 10 mechanics with "Past due" fees
		$info_q = mysql_query( "SELECT nickname AS nick,(SELECT COUNT(*) FROM ".BIDS." LEFT JOIN ".USERS." ON
					".USERS.".id=".BIDS.".bidder_id LEFT JOIN ".WORKLIST." ON
					".WORKLIST.".id=".BIDS.".worklist_id WHERE ".USERS.".nickname=nick
					AND ".WORKLIST.".status='WORKING' AND `".BIDS."`.`accepted`='1'
					AND bid_done < NOW()) AS past_due
					FROM ".USERS." ORDER BY past_due DESC" );

		$info = array();
		while( $row = mysql_fetch_assoc( $info_q ) )	{
			if( count( $info ) < 10 )	{
				if( !empty( $row['nick'] ) )	{
					$info[] = array( $row['nick'],$row['past_due'] );
				}
			}
		}
		echo json_encode( $info );
	}

