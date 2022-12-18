<?php
class Send_Whole_Post extends Plugin {

	private $link;
	private $host;

	function about() {
		return array(0.3,
			"Send whole posts by email",
			"Peliqueiro/mcbyte_it");
	}

	function init($host) {
		$this->host = $host;
		$this->link = $host->get_link();

		$host->add_hook($host::HOOK_ARTICLE_BUTTON, $this);
	}

	function get_js() {
		return file_get_contents(dirname(__FILE__) . "/mail.js");
	}

	function hook_article_button($line) {
		$article_id = $line["id"];

		 $rv = "<img src=\"plugins.local/send_whole_post/sendwholepost.png\"
					class='tagsPic' style=\"cursor : pointer\"
					onclick=\"emailWholeArticle(".$article_id.")\"
					alt='share whole article' title='".__('Share whole post by email')."' />";
					
		return $rv;
	}

	function emailArticle() {

		$param = db_escape_string($_REQUEST['param']);

		$secretkey = sha1(uniqid(rand(), true));

		$_SESSION['email_secretkey'] = $secretkey;

		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"secretkey\" value=\"$secretkey\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"op\" value=\"pluginhandler\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"plugin\" value=\"send_whole_post\">";
		print "<input dojoType=\"dijit.form.TextBox\" style=\"display : none\" name=\"method\" value=\"sendEmail\">";

		$result = db_query("SELECT email, full_name FROM ttrss_users WHERE id = " . $_SESSION["uid"]);

		$user_email = htmlspecialchars(db_fetch_result($result, 0, "email"));
		$user_name = htmlspecialchars(db_fetch_result($result, 0, "full_name"));

		if (!$user_name) $user_name = $_SESSION['name'];

		$_SESSION['email_replyto'] = $user_email;
		$_SESSION['email_fromname'] = $user_name;

		require_once "lib/MiniTemplator.class.php";

		$tpl = new MiniTemplator;

		$tpl->readTemplateFromFile("templates/html_email_article_template.txt");

		$tpl->setVariable('USER_NAME', $user_name);
		//$tpl->setVariable('USER_EMAIL', $user_email);
		//$tpl->setVariable('TTRSS_HOST', $_SERVER["HTTP_HOST"]);


		$result = db_query("SELECT link, content, ent.title as title , author, date_updated, f.title as feed_title, site_url
			FROM ttrss_user_entries as us_ent, ttrss_entries as ent, ttrss_feeds as f WHERE ent.id = us_ent.ref_id AND
			ent.id IN ($param) AND us_ent.owner_uid = " . $_SESSION["uid"] . " AND us_ent.feed_id = f.id");

		if (db_num_rows($result) > 1) {
			$subject = __("[Forwarded]") . " " . __("Multiple articles");
		}
		//Logger::get()->log_error(E_USER_WARNING,"num rows " . db_num_rows($result), 'send_whole_article', 76, false);


		while ($line = db_fetch_assoc($result)) {

			if (!$subject) {
				$subject = __("[Forwarded]") . " " . htmlspecialchars($line["title"]);
			}

			$tpl->setVariable('ARTICLE_TITLE', strip_tags($line["title"]));
			$tpl->setVariable('ARTICLE_URL', strip_tags($line["link"]));
			$tpl->setVariable('ARTICLE_CONTENT', $line["content"]);			
			$tpl->setVariable('ARTICLE_FEED', strip_tags($line["feed_title"]));			
			$tpl->setVariable('SITE_URL', strip_tags($line["site_url"]));			
			$tpl->setVariable('ARTICLE_AUTHOR', strip_tags($line["author"]));			
			$tpl->setVariable('ARTICLE_DATE', strip_tags($line["date_updated"]));			
			$tpl->addBlock('article');

		}

		$tpl->addBlock('email');

		$content = "";
		$tpl->generateOutputToString($content);

		print "<table width='100%'><tr><td>";

		print __('From:');

		print "</td><td>";

		print "<input dojoType=\"dijit.form.TextBox\" disabled=\"1\" style=\"width : 30em;\"
				value=\"$user_name <$user_email>\">";

		print "</td></tr><tr><td>";

		print __('To:');

		print "</td><td>";

		print "<input dojoType=\"dijit.form.ValidationTextBox\" required=\"true\"
				style=\"width : 30em;\"
				name=\"destination\" id=\"emailArticleDlg_destination\">";

		print "<div class=\"autocomplete\" id=\"emailArticleDlg_dst_choices\"
				style=\"z-index: 30; display : none\"></div>";

		print "</td></tr><tr><td>";

		print __('Subject:');

		print "</td><td>";

		print "<input dojoType=\"dijit.form.ValidationTextBox\" required=\"true\"
				style=\"width : 30em;\"
				name=\"subject\" value=\"$subject\" id=\"subject\">";

		print "</td></tr>";

		print "<tr><td colspan='2'><textarea dojoType=\"dijit.form.SimpleTextarea\" style='font-size : 12px; width : 100%' rows=\"20\"
			name='content'>$content</textarea>";

		print "</td></tr></table>";

		print "<div class='dlgButtons'>";
		print "<button dojoType=\"dijit.form.Button\" onclick=\"dijit.byId('emailArticleDlg').execute()\">".__('Send e-mail')."</button> ";
		print "<button dojoType=\"dijit.form.Button\" onclick=\"dijit.byId('emailArticleDlg').hide()\">".__('Cancel')."</button>";
		print "</div>";

		//return;
	}

	function sendEmail() {
		$secretkey = $_REQUEST['secretkey'];

		$reply = array();

		if ($_SESSION['email_secretkey'] && $secretkey == $_SESSION['email_secretkey']) {

			$_SESSION['email_secretkey'] = '';

			$replyto = strip_tags($_SESSION['email_replyto']);
			$fromname = strip_tags($_SESSION['email_fromname']);

		$to = $_REQUEST["destination"];
		$subject = strip_tags($_REQUEST["subject"]);
		$message = strip_tags($_REQUEST["content"]);
		$from = strip_tags("$fromname <$replyto>");

		$mailer = new Mailer();

		$rc = $mailer->mail(["to_address" => $to,
			"headers" => ["Reply-To: $replyto"],
			"subject" => $subject,
			"message" => $message,
			"message_html" => $message]);

		if (!$rc) {
			$reply['error'] =  $mailer->error();
		} else {
			//save_email_address($destination);
			$reply['message'] = "UPDATE_COUNTERS";
		}


		} else {
			$reply['error'] = "Not authorized.";
		}

		print json_encode($reply);
	}

	function completeEmails() {
		$search = db_escape_string($_REQUEST["search"]);

		print "<ul>";

		foreach ($_SESSION['stored_emails'] as $email) {
			if (strpos($email, $search) !== false) {
				print "<li>$email</li>";
			}
		}

		print "</ul>";
	}
	
	function api_version() {
		return 2;
	}

}
?>
