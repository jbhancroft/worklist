<?php
//  vim:ts=4:et
//
//  Copyright (c) 2010, LoveMachine Inc.
//  All Rights Reserved.
//  http://www.lovemachineinc.com
//

ob_start();
include("config.php");
include("class.session_handler.php");
include("check_session.php");
include("functions.php");

$con=mysql_connect(DB_SERVER,DB_USER,DB_PASSWORD);
mysql_select_db(DB_NAME,$con);

$user = new User();
$user->findUserById($_SESSION['userid']);

$audit_mode = ($user->getIs_auditor() && !empty($_REQUEST['audit'])) ? 1 : 0;

if ($audit_mode) {
    $userList = GetUserList($_SESSION['userid'], $_SESSION['nickname'], true, array('is_auditor'));
} else {
    $userList = GetUserList($_SESSION['userid'], $_SESSION['nickname'], true);

    /* Strip users already in the rewarderList */
    $rewarderList = GetRewarderUserList($_SESSION['userid']);
    foreach ($rewarderList as $info) {
        unset($userList[$info[0]]);
    }
}

//-- Overall balance variables ---
	$logPoints = mysql_fetch_row(mysql_query('select sum(`rewarder_points`) from `rewarder_log`;'));
	$totalAlloc = $logPoints[0];
	$DistribPoints = mysql_fetch_row(mysql_query('select sum(`rewarder_points`) from `rewarder_distribution`;'));
	$totalGrant = $DistribPoints[0];
	$percentGranted = ceil(($totalGrant/$totalAlloc)*100);
//-----

/*********************************** HTML layout begins here  *************************************/

include("head.html");
?>

<!-- Add page-specific scripts and styles here, see head.html for global scripts and styles  -->

<link type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" rel="stylesheet" />
<link type="text/css" href="css/rewarder.css" rel="stylesheet" />
<script type="text/javascript" src="js/jquery.sort-1.1.js"></script>
<script type="text/javascript">
    var rewarder = {
        auditMode: <?php echo $audit_mode ? "true" : "false" ?>,
        availPoints: <?php echo $audit_mode ? 0 : $user->getRewarder_points() ?>,
        maxPoints: 0,
        container: null,
        usersContainer: null,
        chartContainer: null,
        chartWidth: 0,
        thumbWidth: 0,
        userHeight: 0,
        rewarderList: [],

        addNewUser: function(newUser, pos) {
            var span = $('<span></span>')
                .text(newUser[1]);
            var user = $('<h6 class="user'+newUser[0]+'"></h6>').append(span).css('top', pos * this.userHeight).data('userid', newUser[0]);
            if (rewarder.auditMode) {
                user.addClass('user-popup').click(function(){
                    rewarder.displayRewarderUserDetail($(this).data('userid'));
                });
            } else {
	            // Add the love popup
	            // 01-MAY-2010 <Andres>
	            user.addClass('user-popup').click(function() {
	                rewarder.showLove($(this).data('userid'), newUser[1]);
	            });
            }
            this.usersContainer.append(user);

            if (rewarder.userHeight == 0) {
                rewarder.userHeight = user.find('span').outerHeight() + 4;
                user.css('top', pos * this.userHeight);
            }

            var userPoints = newUser[2]|0;

            var remover = null;
            if (!rewarder.auditMode) {
                remover = $('<div class="chart-remover user'+newUser[0]+'"><div>')
                    .data('userid', newUser[0])
                    .css('top', pos * rewarder.userHeight)
                    .click(function(){
                        rewarder.deleteRewarderUser($(this).data('userid'), 0);
                    });
                this.chartContainer.append(remover);
            }

            var thumb = $('<div class="chart-thumb">'+rewarder.getPointsText(newUser[2])+'</div>');
            if (rewarder.auditMode) thumb.addClass('chart-thumb-static');
            chart = $('<div class="chart-bar user'+newUser[0]+'"></div>')
                .append(thumb)
                .data('userid', newUser[0])
                .css('top', pos * rewarder.userHeight)
                .css('height', rewarder.userHeight);
            this.chartContainer.append(chart);

            if (rewarder.thumbWidth == 0) rewarder.thumbWidth = thumb.outerWidth();

            rewarder.updateChart(chart, thumb, newUser[2]|0);

            return $(user, remover, chart);
        },

        /* Attach mouse events for capturing dragging of chart thumb.
         *
         * Note: this function called from a jQuery each() method, so 'this', counterintuitively, refers to a DOM
         *       element and not the rewarder object.
         */
        bindDragEvents: function(i) {
            if (rewarder.auditMode) return;

            var bar, thumb;
            var dragStartX, thumbStartX, startPoints;

            var capture = function(){
                $(document)
                    .mousemove(function(e){
                        if (!hasStarted) return;

                        var max = bar.width() - rewarder.thumbWidth + 1;
                        var left = Math.max(0, Math.min(max, thumbStartX + e.pageX - dragStartX));
                        var points = Math.min(bar.data('maxPoints'), (rewarder.maxPoints * (left / (rewarder.chartWidth - rewarder.thumbWidth)))|0);
                        thumb.css('left', left).text(rewarder.getPointsText(points - startPoints, true));
                        if (points - startPoints < 0) thumb.addClass('chart-negative');
                        else thumb.removeClass('chart-negative');

                        $('#rewarder-points').text(rewarder.getPointsText(rewarder.availPoints - (points - startPoints)));

                        bar.data('points', points)
                        return false;
                    })
                    .mouseup(function(e){
                        $(document).unbind();
                        thumb.removeClass('chart-negative');
                        rewarder.updateRewarderUser(bar.data('userid'), bar.data('points'));
                    });
            };

            $(this).unbind().mousedown(function(e){
                thumb = $(this);
                bar = $(this).parent();

                var pos = thumb.position();
                dragStartX = e.pageX;
                thumbStartX = pos.left;
                startPoints = bar.data('points');
                hasStarted = true;
                capture();
                return false;
            });
        },

        /*
         * combinedList has the structure:
         *   combinedList[ user_id ] = [ enum:{0=remove,1=keep,2=new}, posDelta, user ]
         */
        combineUserLists: function(oldList, newList) {
            var user_id;
            var combinedList = new Array();

            /* Fill with old users */
            for (var i = 0; i < oldList.length; i++) {
                user_id = oldList[i][0];
                combinedList[user_id] = new Array(0, i);
            }

            for (var i = 0; i < newList.length; i++) {
                user_id = newList[i][0];
                /* Existing users */
                if (combinedList[user_id] != undefined) {
                    combinedList[user_id][0] = 1;
                    combinedList[user_id][1] = i;
                /* Removed users */
                } else {
                    combinedList[user_id] = new Array(2, i, newList[i]);
                }
            }

            return combinedList;
        },

        deleteRewarderUser: function(userid) {
            $.ajax({
                url: 'update-rewarder-user.php',
                data: 'id='+userid+'&delete=1',
                dataType: 'json',
                type: "POST",
                cache: false,
                success: function(json) {
                    rewarder.availPoints = json[0]|0;
                    $('#rewarder-points').text(rewarder.getPointsText(rewarder.availPoints));

                    rewarder.updateRewarderList(json[1]);
                }
            });
        },

        displayRewarderUserDetail: function(userid) {
            // Show love sent to clicked user
            // 1-MAY-2010 <andres>
            var love_sent;
            $.ajax({
                url: 'get-love.php',
                data: 'id='+userid,
                dataType: 'json',
                type: "POST",
                cache: false,
                success: function(json) {
                    love_sent = json;
                }
            });
            
            $.ajax({
                url: 'get-rewarder-user-detail.php',
                data: 'id='+userid,
                dataType: 'json',
                type: "POST",
                cache: false,
                success: function(json) {
                    var detailHTML = 
                        '<div id="detail" title="Rewarder Detail for '+json[0]+'">' +
                        '<div id="audit" style="float:left;">' +
                        '  <table style="text-align: left">' +
                        '    <tr class="table-hdng"><th style="width: 120px">User</th><th style="width: 100px">Points</th></tr>';

                    for (var i = 0; i < json[1].length; i++) {
                        detailHTML += '    <tr><td>'+json[1][i][0]+'</td><td>'+json[1][i][1]+' points</td></tr>';
                    }

                    // Show love sent to clicked user
                    // 1-MAY-2010 <andres>
                    detailHTML += '  </table></div>' +
                        '  <div id="love" style="float:left; margin-left:15px;">' +
                        '      <table stlye="text-align:left;">' +
                        '      <tr class="table-hdng"><th>Why</th><th style="width: 25%;">When</th></tr>';
                    for (var i = 0; i < love_sent.length; i++) {
                        var json_when = love_sent[i].when;
                        var when = relativeTime(json_when);
                        detailHTML += '    <tr><td>'+love_sent[i].why+'</td><td>'+when+'</td></tr>';
                    }

                    if (love_sent.length == 0) {
                        // Add a no love sent message
                        detailHTML += '    <tr><td style="text-align:center;" colspan="2">No love sent to '+json[0]+'</td></tr>';
                    }

                    detailHTML += 
                        '    </table></div>' +
                        '  <div style="clear:both;"></div>' +
                        '  <form id="detailForm" method="post">' +
                        '    <input type="submit" name="submit" value="Ok" />' +
                        '  </form>';
                    '</div>';
                    var detail = $(detailHTML).dialog({ modal: true, width: 'auto', height: 'auto' });

                    $("#detailForm").submit(function(){
                        detail.dialog('close');
                        detail.remove();
                        return false;
                    });
                }
            });
        },

        getMaxPoints: function (rewarderList) {
            var maxPoints = 0;
            for (var i = 0; i < rewarderList.length; i++){
                maxPoints = Math.max(maxPoints, rewarderList[i][2]);
            }
            return maxPoints;
        },

        getPointsText: function (points, includeSign) {
            var txt = '';
            if (includeSign && points > 0) txt += '+';
            txt += points + ' point';
            if (points != -1 && points != 1) txt += 's';
            return txt;
        },

        loadAuditList: function() {
            $.ajax({
                url: 'get-audit-list.php',
                dataType: 'json',
                type: "POST",
                cache: false,
                success: function(json) {
                    rewarder.updateRewarderList(json);
                }
            });
        },

        loadRewarderList: function() {
            $.ajax({
                url: 'get-rewarder-list.php',
                dataType: 'json',
                type: "POST",
                cache: false,
                success: function(json) {
                    rewarder.availPoints = json[0]|0;
                    $('#rewarder-points').text(rewarder.getPointsText(rewarder.availPoints));

                    rewarder.updateRewarderList(json[1]);
                }
            });
        },

        toggleRewarderAuditor: function(el){
            var userid = el.val();
            $.ajax({
                url: 'update-rewarder-auditor.php',
                data: 'id='+userid+'&toggle=1',
                type: "POST",
                cache: false,
                success: function(data) {
                    var opt = el.find('option:selected');
                    if (opt.text()[0] == '*') {
                        opt.text(opt.text().substr(2));
                    } else {
                        opt.text('* '+opt.text());
                    }
                    opt.attr('selected','');
                }
            });
        },

        updateRewarderList: function(newRewarderList) {
            this.maxPoints = this.getMaxPoints(newRewarderList) + this.availPoints;

            if (!this.usersContainer) {
                this.container = $('#rewarder-container');
                this.usersContainer = $('#rewarder-users');
                this.chartContainer = $('#rewarder-chart');
                this.chartWidth = this.chartContainer.width() * .95;
            }

            /* If this is a fresh load of the rewarder list, just fill all the users. */
            if (rewarder.rewarderList.length == 0 && newRewarderList.length > 0) {
                for (var i = 0; i < newRewarderList.length; i++){
                    this.addNewUser(newRewarderList[i], i);
                }

            /* Real changes including add new users, remove out old users, reposition users. */
            } else {
                var combinedList = rewarder.combineUserLists(rewarder.rewarderList, newRewarderList);

                var animateFadeIn = function() { 
                    var j = 0;
                    for (var i in combinedList) {
                        if (combinedList[i][0] == 2) {
                            var user = rewarder.addNewUser(combinedList[i][2], combinedList[i][1]);
                            user.hide().fadeIn('slow');
                        }
                    }
                };

                var animateReposition = function() { 
                    var j = 0;
                    var fn = animateFadeIn;
                    for (var i in combinedList) {
                        if (combinedList[i][0] == 1) {
                            rewarder.container.find('.user'+i).each(function(){
                                $(this).animate({top: combinedList[i][1] * rewarder.userHeight}, 'slow', fn);
                                fn = null;
                            });
                        }
                    }

                    if (fn) animateFadeIn();
                }

                var animateFadeOut = function() { 
                    var j = 0;
                    var fn = animateReposition;
                    for (var i in combinedList) {
                        if (combinedList[i][0] == 0) {
                            rewarder.container.find('.user'+i).each(function(){
                                $(this).fadeOut('slow', fn).remove();
                                fn = null;
                            });
                        }
                    }

                    if (fn) animateReposition();
                };

                animateFadeOut();
            }

            /* Update points */
            for (var i = 0; i < newRewarderList.length; i++) {
                var chart = this.chartContainer.find('.chart-bar.user'+newRewarderList[i][0]);
                var thumb = chart.find('.chart-thumb');
                rewarder.updateChart(chart, thumb, newRewarderList[i][2]|0);
            }

            this.rewarderList = newRewarderList;
        },

        updateRewarderUser: function(userid, points){
            $.ajax({
                url: 'update-rewarder-user.php',
                data: 'id='+userid+'&points='+points,
                dataType: 'json',
                type: "POST",
                cache: false,
                success: function(json) {
                    rewarder.availPoints = json[0]|0;
                    $('#rewarder-points').text(rewarder.getPointsText(rewarder.availPoints));

                    rewarder.updateRewarderList(json[1]);
                }
            });
        },

        updateChart: function(chart, thumb, points){
            chart
                .data('points', points)
                .data('maxPoints', points + rewarder.availPoints);

            var width = ((rewarder.chartWidth - rewarder.thumbWidth) * ((points + rewarder.availPoints) / rewarder.maxPoints) + rewarder.thumbWidth)|0;
            chart.css('width', width);

            chart.find('.chart-thumb')
                .each(rewarder.bindDragEvents)
                .css('left', (rewarder.chartWidth - rewarder.thumbWidth) * (points / rewarder.maxPoints));

            thumb.text(rewarder.getPointsText(points));
        },

        // Show love sent to clicked user
        // 1-MAY-2010 <andres>
        showLove: function(userid, name) {
            $.ajax({
                url: 'get-love.php',
                data: 'id='+userid,
                dataType: 'json',
                type: "POST",
                cache: false,
                success: function(json) {
                    var detailHTML = 
                        '<div id="detail" title="Love sent to '+name+'">' +
                        '  <table style="text-align: left">' +
                        '    <tr class="table-hdng"><th>Why</th><th style="width: 25%;">When</th></tr>';

                    for (var i = 0; i < json.length; i++) {
                        var json_when = json[i].when;
                        var when = relativeTime(json_when);
                        detailHTML += '    <tr><td>'+json[i].why+'</td><td>'+when+'</td></tr>';
                    }

                    if (json.length == 0) {
                        // Add a no love sent message
                        detailHTML += '    <tr><td style="text-align:center;" colspan="2">No love sent to '+name+'</td></tr>';
                    }

                    detailHTML +=
                        '  </table>' +
                        '  <form id="detailForm" method="post">' +
                        '    <input type="submit" name="submit" value="Ok" />' +
                        '  </form>';
                    '</div>';
                    var detail = $(detailHTML).dialog({ modal: true, width: 'auto', height: 'auto' });

                    $("#detailForm").submit(function(){
                        detail.dialog('close');
                        detail.remove();
                        return false;
                    });
                }
            });
        }
    };

    $(window).ready(function(){
        if (rewarder.auditMode) {
            $('#user-list').change(function(){
                rewarder.toggleRewarderAuditor($(this));
            });

            rewarder.loadAuditList();
        } else {
            $('#user-list').change(function(){
                var userid = $(this).val();
                rewarder.updateRewarderUser(userid, 0);
                $(this).find('option:selected').remove();
            });
    
            rewarder.loadRewarderList();
        }
		
		$('#users-header').toggle(function(){
			var r = rewarder.rewarderList;
			r.sort(alphaNumSort);
			r.reverse();
			rewarder.updateRewarderList(r);
			$('#points-arrow').hide();
			$('#users-arrow').removeClass('arrow-up').addClass('arrow-down').show();
			$(this).attr('title','Sort A-Z');
		},function(){
			var r = rewarder.rewarderList;
			r.sort(alphaNumSort);
			rewarder.updateRewarderList(r);
			$('#points-arrow').hide();
			$('#users-arrow').removeClass('arrow-down').addClass('arrow-up').show();
			$(this).attr('title','Sort Z-A');
		});
		
		$('#points-arrow').hide();
		$('#points-header').toggle(function(){
			var r = rewarder.rewarderList;
			r.sort(numSort);
			r.reverse();
			rewarder.updateRewarderList(r);
			$('#users-arrow').hide();
			$('#points-arrow').removeClass('arrow-up').addClass('arrow-down').show();
			$(this).attr('title','Sort Ascendent');
		},function(){
			var r = rewarder.rewarderList;
			r.sort(numSort);
			rewarder.updateRewarderList(r);
			$('#users-arrow').hide();
			$('#points-arrow').removeClass('arrow-down').addClass('arrow-up').show();
			$(this).attr('title','Sort Descendent');
		});
		
		function alphaNumSort(m,n){
			try{
				var cnt= 0,tem;
				var a= m[1].toLowerCase();
				var b= n[1].toLowerCase();
				if(a== b) return 0;
				var x=/^(\.)?\d/;
			
				var L= Math.min(a.length,b.length)+ 1;
				while(cnt< L && a.charAt(cnt)=== b.charAt(cnt) &&
				x.test(b.substring(cnt))== false && x.test(a.substring(cnt))== false) cnt++;
				a= a.substring(cnt);
				b= b.substring(cnt);
			
				if(x.test(a) || x.test(b)){
					if(x.test(a)== false)return (a)? 1: -1;
					else if(x.test(b)== false)return (b)? -1: 1;
					else{
						var tem= parseFloat(a)-parseFloat(b);
						if(tem!= 0) return tem;
						else tem= a.search(/[^\.\d]/);
						if(tem== -1) tem= b.search(/[^\.\d]/);
						a= a.substring(tem);
						b= b.substring(tem);
					}
				}
				if(a== b) return 0;
				else return (a >b)? 1: -1;
			}
			catch(er){
				return 0;
			}
		}
		
		function numSort(m,n){
			try{
				var a = parseInt(m[2]);
				var b = parseInt(n[2]);
				if(a== b) return 0;
				else return (a >b)? 1: -1;
			}
			catch(er){
				return 0;
			}
		}
    });

    function relativeTime(x) {
        var plural = '';

        var mins = 60, hour = mins * 60; day = hour * 24,
            week = day * 7, month = day * 30, year = day * 365;

        if (x >= year) { x = (x / year)|0; dformat="yr"; }
        else if (x >= month) { x = (x / month)|0; dformat="mnth"; }
        else if (x >= day) { x = (x / day)|0; dformat="day"; }
        else if (x >= hour) { x = (x / hour)|0; dformat="hr"; }
        else if (x >= mins) { x = (x / mins)|0; dformat="min"; }
        else { x |= 0; dformat="sec"; }
        if (x > 1) plural = 's';
        return x + ' ' + dformat + plural + ' ago';
    }
</script>

<title>Worklist | Rewarder</title>

</head>

<body>

<?php include("format.php"); ?>

<!-- ---------------------- BEGIN MAIN CONTENT HERE ---------------------- -->


    <h1>Rewarder</h1>

    <div id="rewarder-controls">
	
	   <?php if ($_SESSION['is_auditor'] && $audit_mode) { ?>
			
			<div id="rewarder_balance">
				<div class="balanceTitle">Overall balance</div>
				<div style="display:table-row">
					<div id="percentGrant">
						<span class="balancePercent"><?php echo $percentGranted;?></span>&nbsp;<span style="">%</span><br/>
						<span style="margin-top:-3px;">granted</span>
					</div>
					<div id="balanceDetails">
						<div id="totPointsAlloc">
							<span class="balanceLabel">Allocated</span><span class="balanceData"><?php echo $totalAlloc; ?></span>
						</div>
						<div id="totPointsGrant">
							<span class="balanceLabel">Granted</span><span class="balanceData"><?php echo $totalGrant; ?></span>
						</div>
					</div>
				</div>
			</div>
		<?php } ?>
        <div id="rewarder-point-info">
			<?php if ($_SESSION['is_auditor']) { ?>
                <?php if ($audit_mode) { ?>
                <a href="rewarder.php">Award</a>
                <?php } else { ?>
                <a href="rewarder.php?audit=1">Audit</a> |
                Your Rewarder balance is <span id="rewarder-points"><?php echo $user->getRewarder_points() ?> points</span>
                <?php } ?>
            <?php } else { ?>
            Your Rewarder balance is <span id="rewarder-points"><?php echo $user->getRewarder_points() ?> points</span>
            <?php } ?>
        </div>
        <div id="rewarder-team">
            <?php if ($audit_mode) { ?>
            <label for="user-list">Manage Auditors:</label>&nbsp;
            <?php } else { ?>
            <label for="user-list">Reward:</label>&nbsp;
            <?php } ?>
            <select id="user-list" name="user-list">
                <?php if ($audit_mode) { ?>
                    <option value="0">-- toggle auditor --</option>
                    <?php foreach ($userList as $userid=>$info) { ?>
                    <option value="<?php echo $userid ?>"><?php echo ($info['is_auditor'] ? '* ' : '') . $info['nickname'] ?></option>
                    <?php } ?>
                <?php } else { ?>
                    <option value="0">-- team member --</option>
                    <?php foreach ($userList as $userid=>$nickname) { ?>
                    <option value="<?php echo $userid ?>"><?php echo $nickname ?></option>
                    <?php } ?>
                <?php } ?>
            </select>
        </div>
    </div>
    <div style="clear:both"></div>
	<div id="list-headers">
		<div id="users-header" title='Sort Z-A'>User <div id="users-arrow" class="arrow arrow-up"></div></div>
		<div id="points-header"title='Sort Descendent'>Points Rewarded <div id="points-arrow" class="arrow arrow-down"></div></div>
	</div>
    <div id="rewarder-container">
        <div id="rewarder-users"></div>
        <div id="rewarder-chart"></div>
    </div>
    <div style="clear:both"></div>

<!-- ---------------------- end MAIN CONTENT HERE ---------------------- -->

<?php include("footer.php"); ?>
