    <div id="popup-edit" title = "Add Worklist Item" class = "popup-body">
            <form name="popup-form" id="popup-form-edit" action="" method="post">
                <input type="hidden" name="itemid" value="0" />

                <input type="hidden" name="page" value="<?php echo $page ?>" class="popup-page-value" />

		<div id = "for_edit" <?php if(!isset($_SESSION['userid'])){ echo 'style = "display:none;"';}?>>
		  <p><label>Summary<br />
		  <input type="text" name="summary" id="summary" class="text-field" size="48" />
		  </label></p>

		  <input type="hidden" id="owner" name="owner" /><!-- for now -->
                  <script type="text/javascript">
                    var summary = new LiveValidation('summary',{ onlyOnSubmit: true });
                        summary.add( Validate.Presence, { failureMessage: "Can't be empty!" });
                  </script>


  <?php
  $is_runner = isset($_SESSION['is_runner']) ? $_SESSION['is_runner'] : 0;
  if ($is_runner) {//if user is a runner - allow to change status
  ?>
		  <p><label>Status<br />
		  <select name="status">
		      <option value="BIDDING" selected = "selected" >BIDDING</option>
		      <option value="WORKING">WORKING</option>
		      <option value="SKIP">SKIP</option>
		      <option value="DONE">DONE</option>
		  </select>
		  </label></p>
  <?php }else {?>
		  <input type="hidden" id="status" name="status" value = "BIDDING" />    
  <?php }?>
		  <p><label>Notes<br />
		  <textarea name="notes" size="48" /></textarea>
		  </label></p>
		</div><!-- end div #for-edit-->
		<div id = "for_view">
		  <p class = "info-label">Summary<br />
		  <span id="info-summary"></span>
		  </p>

		  <p class = "info-label">Satus<br />
		  <span id="info-status"></span>
		  </p>

		  <p class = "info-label">Notes<br />
		  <span id="info-notes"></span>
		  </p>
		</div><!-- end div #for_view -->
                <?php if (isset($_SESSION['userid'])) { ?>
		<div id = "fees_block">
		  Fees
		  <table width="100%" class="table-feelist">
		      <thead>
		      <tr class="table-hdng" >
			  <td>Who</td>
			  <td>Amount</td>
			  <td>Description</td>
			  <td>Date</td>
			  <td>Paid</td>
		      </tr>
		      </thead>
		      <tbody>
		      </tbody>
		  </table><br />    

		  <p>
		    <input type="submit" name="add_fee_dialog" value="Add Fee">
		  </p>
		</div><!-- end of fees_block -->
                <input type="submit" name="save" value="Save">
                <input type="submit" name="reset" value="Reset">
                <input type="submit" name="cancel" value="Cancel">
                <?php } else { ?>
		<div id = "bid-signup">
		<h3>Want to bid?</h3>
		<a href="signup.php">Sign up now!</a>
		</div>
                <?php } ?>
            </form>
        </div>