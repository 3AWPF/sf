<?php

/**
 * A thread contains various posts within it.
 * @author Calclavia
 */
class Thread extends ForumElement
{

	function __construct($id, $parent, $name, $sticky, $lockThread, $views)
	{
		$this->id = $id;
		$this->name = clean(str_replace("\\r\\n", "", $name), true);

		$this->element_name = "threads";
		$this->prefix = "t";

		$this->fields["Parent"] = $parent;
		$this->fields["Sticky"] = $sticky;
		$this->fields["LockThread"] = $lockThread;
		$this->fields["Views"] = $views;
	}

	public static function setUp($con)
	{
		global $table_prefix;

		mysql_query("CREATE TABLE IF NOT EXISTS {$table_prefix}threads (ID int NOT NULL AUTO_INCREMENT, PRIMARY KEY(ID), Name varchar(255), Parent int, Sticky varchar(5), LockThread varchar(5), Views int)", $con) or die(mysql_error());
	}

	public static function getByID($id)
	{
		global $table_prefix;

		$result = mysql_query("SELECT * FROM {$table_prefix}threads
		WHERE ID={$id} LIMIT 1");

		$row = mysql_fetch_array($result);

		if ($row["ID"] <= 0)
		{
			return null;
		}
		else
		{
			return new Thread($row["ID"], $row["Parent"], $row["Name"], $row["Sticky"], $row["LockThread"], $row["Views"]);
		}
	}

	public function getChildren()
	{
		global $table_prefix;

		$returnArray = array();

		$result = mysql_query("SELECT * FROM {$table_prefix}posts WHERE Parent={$this->id}");

		while ($row = mysql_fetch_array($result))
		{
			$returnArray[] = new Post($row["ID"], $row["Parent"], $row["Name"], $row["Content"], $row["User"], $row["Time"], $row["LastEditTime"], $row["LastEditUser"]);
		}

		uasort($returnArray, function ($a, $b)
		{
			return $a->fields["Time"] > $b->fields["Time"];
		});

		return $returnArray;
	}

	/**
	 * @return array Returns all the posts in this thread.
	 */
	public function getPosts()
	{
		return $this->getChildren();
	}

	public function createPost($content, $user, $time, $con)
	{
		global $websiteName;

		if ($this->fields["LockThread"] != "yes")
		{
			$post = new Post(-1, $this->id, $this->name, $content, $user->id, $time, $time, $userID);

			if ($post->save($con))
			{
				$user->onCreatePost($post, $con);

				$pageURL = (@$_SERVER["HTTPS"] == "on") ? "https://" : "http://";
				$pageURL .= $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];

				$users = $this->getWatching($con);

				foreach ($users as $watchingUser)
				{
					if ($watchingUser->id != $user->id)
					{
						$watchingUser->email("{$websiteName}: New Post in {$this->name}!", "Dear {$watchingUser->username},\n   The thread you were watching called \"{$this->name}\" had a new post! Come check it out here: \n $pageURL");
					}
				}

				return $post;
			}
		}

		return null;
	}

	public function move($user, $boardID)
	{
		global $permission;

		if ($boardID > 0)
		{
			$board = Board::getByID($boardID);

			if ($board != null)
			{
				if ($user->hasPermission($permission["thread_move"], Board::getByID($this->fields["Parent"])))
				{
					$this->fields["Parent"] = $board->getID();
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @return Array<ForumUser> Gets an array of ForumUsers who are watching this thread.
	 */
	public function getWatching($con)
	{
		$returnArray = array();
		$users = ForumUser::getAll($con);

		foreach ($users as $watchingUser)
		{
			if ($watchingUser->isWatching($this))
			{
				$returnArray[] = $watchingUser;
			}
		}

		return $returnArray;
	}

	public function getFirstPost()
	{
		$threads = $this->getChildren();

		if (count($threads) > 0)
		{
			$earliestThread = $threads[0];

			foreach ($threads as $thread)
			{
				if ($thread->fields["Time"] < $earliestThread->fields["Time"])
				{
					$earliestThread = $thread;
				}
			}

			return $earliestThread;
		}

		return null;
	}
	
	public function getParent()
	{
		return Board::getByID($this->fields["Parent"]);
	}

	public function getLatestPost()
	{
		$posts = $this->getChildren();

		if (count($posts) > 0)
		{
			$latestThread = $posts[0];

			foreach ($posts as $thread)
			{
				if ($thread->fields["Time"] > $latestThread->fields["Time"])
				{
					$latestThread = $thread;
				}
			}

			return $latestThread;
		}

		return null;
	}

	public function getTreeAsString()
	{
		return str_replace("</ul>", "<li><a href='{$_SERVER['PHP_SELF']}?p=t{$this->getID()}' class='current'>" . limitString($this->name, 30) . "</a></li></ul>", str_replace("class='current'", "", Board::getByID(intval($this->fields["Parent"]))->getTreeAsString()));
	}

	public function view($user, $con)
	{
		foreach ($this->getChildren() as $post)
		{
			$user->read($post, $con);
		}

		$this->fields["Views"]++;
		$this->save($con);
	}

	public function editTitle($user, $name)
	{
		global $permission;

		if ($user->hasPermission($permission['thread_edit'], $this))
		{
			$this->name = $name;
		}
	}

	public function stickThread($user, $status)
	{
		global $permission;

		if ($user->hasPermission($permission['thread_sticky'], $this))
		{
			$this->fields["Sticky"] = ($status ? "yes" : "no");
		}
	}

	public function lockThread($user, $status = true)
	{
		global $permission;

		if ($user->hasPermission($permission['thread_lock'], $this))
		{
			$this->fields["LockThread"] = ($status ? "yes" : "no");
		}
	}

	public function isUnread($user)
	{
		foreach ($this->getChildren() as $child)
		{
			if ($child->isUnread($user))
			{
				return true;
			}
		}

		return false;
	}

	public function getViews()
	{
		return $this->fields["Views"];
	}

	public function printThread($user)
	{
		$stats = count($this->getChildren()) . " post(s) " . $this->getViews() . " view(s)";

		$printLatestPost = "No posts.";

		$latestPost = $this->getLatestPost();

		if ($latestPost->fields["User"] != null)
		{
			$latestPostUser = getUserByID($latestPost->fields["User"]);
			$printLatestPost = "Last Post By: <b>" . $latestPostUser->username . "</b><br />" . $latestPost->getDate();
		}

		$thisOwner = "Annoymous";

		if ($this->getFirstPost()->fields["User"] != null)
		{
			$userdetails = fetchUserDetails(null, null, $this->getFirstPost()->fields["User"]);
			$thisOwner = $userdetails["display_name"];
		}

		return "
            <div class='thread_wrapper " . ($this->isUnread($user) ? "thread_unread" : ($this->fields["Sticky"] == "yes" ? "thread_sticky" : "thread_normal")) . "'>
            <div class='forum_element'>
                    <div class='two_third thread_content'>
                         <h3 class='element_title'><a href='{$_SERVER['PHP_SELF']}?p=t{$this->getID()}'>{$this->name}</a></h3>
                         <div class='forum_element_info'>
                               $thisOwner, {$this->getFirstPost()->getDate()}
                         </div>
                    </div>
                    <div class='forum_element_info one_third column-last'>
                         $printLatestPost <br/> $stats
                    </div>
                    <div class='clear'></div>
                </div>
            </div>
            <div class='hrline_silver' style='width: 95%'></div>";
	}

	/**
	 * @param ForumUser $user - The current user
	 * @param Integer $currentPage - The curent page
	 * @return string The HTML content.
	 */
	public function printThreadContent($user, $con, $currentPage = 1)
	{
		global $permission, $posts_per_page;

		if ($currentPage <= 0)
			$currentPage = 1;

		if ($this != null)
		{

			$printContent .= "
			<div class='thread'>
			
            <div class=\"forum_menu\">";

			if ($user->hasPermission($permission["thread_sticky"], Board::getByID($this->fields["Parent"])))
			{
				$stick = "<span class='hidden_field'>Stick: <input type='checkbox' id='sticky_{$this->getID()}' " . ($this->fields["Sticky"] == "yes" ? "checked='checked'" : "") . "></span>";
			}

			if ($user->hasPermission($permission["thread_lock"], $this))
			{
				$lock = "<span class='hidden_field'>Lock: <input type='checkbox' id='lock_{$this->getID()}' " . ($this->fields["LockThread"] == "yes" ? "checked='checked'" : "") . "></span>";
			}

			if ($user->hasPermission($permission["thread_move"], Board::getByID($this->fields["Parent"])))
			{
				$move = "<span class='hidden_field'>Move:<select id='move_{$this->getID()}'>";
				$move .= "<option value='-1'>--</option>";

				$categories = Category::getAll($con);
				foreach ($categories as $category)
				{
					if ($category != null)
					{
						foreach ($category->getChildren() as $board)
						{
							$move .= "<option value='{$board->getID()}'>{$board->name}</option>";

							foreach ($board->getAllSubBoards($con) as $subBoard)
							{
								$indent = "";

								foreach ($subBoard->getAllParents($con) as $parent)
								{
									$indent .= " -";
								}

								$move .= "<option value='{$subBoard->getID()}'>{$indent} {$subBoard->name}</option>";
							}
						}
					}
				}

				$move .= "</select></span>";
			}

			if ($user->hasPermission($permission["thread_edit"], $this))
			{
				$printContent .= "<a href=\"javascript:void(0)\" data-forum-target='{$this->getID()}' class='thread_edit btn_small btn_silver btn_flat'>Edit</a> ";
			}

			if ($user->hasPermission($permission["thread_watch"], $this))
			{
				$printContent .= "<a href=\"javascript:void(0)\" data-forum-target='{$this->getID()}'class='thread_watch btn_small btn_silver btn_flat'>" . ($user->isWatching($this) ? "Unwatch" : "Watch") . " Thread (" . count($this->getWatching($con)) . ")</a> ";
			}

			if ($user->hasPermission($permission["post_create"], $this) && $this->fields["LockThread"] != "yes")
			{
				$printContent .= "<a href = \"javascript:$('html, body').animate({scrollTop:  $(document).height()})\" class='btn_small btn_silver btn_flat'>+ Post</a>";
			}

			$printContent .= "
			</div>
			<div>
				<h2 id='thread_title_{$this->getID()}' class='editable_title'>{$this->name}</h2>
				$stick $lock $move
			</div>
			<div class='clear'></div><div class='elements_container'>" . $this->getTreeAsString();

			if (count($this->getChildren()) > 0)
			{
				$posts = $this->getChildren();

				//Each page will contain 10 posts.
				$pages = array_chunk($posts, $posts_per_page);

				$i = 1;

				$pagination = "
                    <ul class='pagination'>
                    <li><a href='{$_SERVER['PHP_SELF']}?p=t{$this->getID()}&page=1' class='first'>First</a></li>
                    <li><a href='{$_SERVER['PHP_SELF']}?p=t{$this->getID()}&page=" . max($currentPage - 1, 1) . "' class='previous'>Previous</a></li>";

				foreach ($pages as $page)
				{
					if ($i == $currentPage)
					{
						/**
						 * Print out each and every post.
						 */
						foreach ($page as $post)
						{
							$printContent .= $post->printPost($user, getUserByID($post->fields["User"]));
						}

						$pagination .= "<li><a href='#' class='current'>" . $i . "</a></li>";
					}
					else if ($currentPage < $i && $currentPage > $i - 3 || $currentPage > $i && $currentPage < $i + 3)
					{
						$pagination .= "<li><a href='{$_SERVER['PHP_SELF']}?p=t{$this->getID()}&page=$i'>" . $i . "</a></li>";
					}

					$i++;
				}

				$pagination .= "
                    <li><a href='{$_SERVER['PHP_SELF']}?p=t{$this->getID()}&page=" . max(min($currentPage + 1, $i - 1), 1) . "' class='next'>Next</a></li>
                    <li><a href='{$_SERVER['PHP_SELF']}?p=t{$this->getID()}&page=" . ($i - 1) . "' class='last'>Last</a></li>
                    </ul>";

				/**
				 * Print out add new post form.
				 */
				if ($user->hasPermission($permission["post_create"], $this) && $this->fields["LockThread"] != "yes")
				{
					$printContent .= $this->printNewPostForm($user, $currentPage);
				}
			}
			else
			{
				$printContent .= "No posts avaliable.";
			}

			$printContent .= "<div class='page_numbers'>" . $pagination . "</div>" . $this->getTreeAsString() . "</div></div>";

			return $printContent;
		}
	}

	public function printNewPostForm($user, $currentPage = 1)
	{
		return "
		<div class='post'>
			<a rel='new'></a>
			" . $user->printProfile() . "
			<div class='comment_box'>
				<div class='comment_inner'>
					<form action='{$_SERVER['PHP_SELF']}?p=t{$this->getID()}&page={$currentPage}&a=new' method='post'>
						<textarea id='editableContentNewPost' name='editableContent' style='width:100%; height: 300px;'></textarea><br />
						<input type='submit' value='Post'/>
						<script>var postEditor = CKEDITOR.replace('editableContentNewPost', {height:'250', width: '548'});</script>
					</form>
				</div>
			</div>
			<div class='clear'></div>
		</div>
    	";
	}

}

?>