<?php
/**
 * The Board module handles requests concerning the "root" of the phpBB installation--statistics, the forum list, etc.
 *
 * @package phpbb.json
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 * @author  Florin Pavel
 */

namespace phpBBJson\Modules;

class Board extends Base
{
	/**
	 * Lists visible forums and some pertinent information for each forum.
	 * If authentication is supplied, the forum list will consist of the forums the given user is allowed to see.
	 * If no authentication is supplied, only guest-visible forums will be display.
	 *
	 * <b>Data:</b>
	 * <ul>
	 *  <li>parent_id(integer, optional) - The parent forum for returned forums. Defaults to 0 (all forums displayed).</li>
	 *  <li>secret(string, optional) - The authentication code</li>
	 * </ul>
	 *
	 * <b>Result</b>: A two-dimensional JSON array is returned.
	 * @param \Slim\Http\Request  $request
	 * @param \Slim\Http\Response $response
	 * @param string[]            $args
	 * @return \Slim\Http\Response
	 * @throws \phpBBJson\Exception\InternalError
	 */
	public function boardList($request, $response, $args)
	{
		$params = $request->getQueryParams();
		$secret = isset($params['secret']) && \phpBBJson\verifySecret($params['secret']) ? $params['secret'] : null;

		$parent_id = isset($args['parentId']) ? $args['parentId'] : null;

		if ($parent_id == '' || empty($parent_id)) {
			$parent_id = 0;
		}

		$sql_array = [
			'SELECT'    => 'f.*',
			'FROM'      => [
				FORUMS_TABLE => 'f'
			],
			'LEFT_JOIN' => array(),
		];

		if ($parent_id == 0) {
			$sql_where = '';
		} else {
			$sql_where = 'parent_id = ' . $parent_id;
		}

		$db      = $this->phpBB->get_db();
		$auth    = $this->phpBB->get_auth();
		$user    = $this->phpBB->get_user();
		$user_id = null;
		if ($secret != null) {
			$user_id                  = \phpBBJson\getIdFromSecret($secret);
			$sql_array['LEFT_JOIN'][] = array(
				'FROM' => array(
					FORUMS_TRACK_TABLE => 'ft'
				),
				'ON'   => 'ft.user_id = ' . $user_id . ' AND ft.forum_id = f.forum_id'
			);
			$sql_array['SELECT'] .= ', ft.mark_time';
			$userdata = \phpBBJson\userdata($user_id);

		} else {
			$user->session_begin();
			$userdata = $user->data;
		}
		$auth->acl($userdata);

		$sql = $db->sql_build_query(
			'SELECT',
			array(
				'SELECT'    => $sql_array['SELECT'],
				'FROM'      => $sql_array['FROM'],
				'LEFT_JOIN' => $sql_array['LEFT_JOIN'],
				'WHERE'     => $sql_where
			)
		);

		$result = $db->sql_query($sql);

		$forums = array();
		while ($row = $db->sql_fetchrow($result)) {

			$forum_id = $row['forum_id'];

			// Category with no members
			if ($row['forum_type'] == 0 && ($row['left_id'] + 1 == $row['right_id'])) {
				continue;
			}

			// Skip branch
			if (isset($right_id)) {
				if ($row['left_id'] < $right_id) {
					continue;
				}
				unset($right_id);
			}

			if (!$auth->acl_get('f_list', $forum_id)) {
				// if the user does not have permissions to list this forum, skip everything until next branch
				$right_id = $row['right_id'];
				continue;
			}

			$forums[] = array(
				'forum_id'             => $row['forum_id'],
				'parent_id'            => $row['parent_id'],
				'forum_name'           => $row['forum_name'],
				'unread'               => ($row['mark_time'] == null) ? true : false,
				'total_topics'         => $row['forum_topics'],
				'total_posts'          => $row['forum_posts'],
				'last_poster_id'       => $row['forum_last_poster_id'],
				'last_poster_name'     => $row['forum_last_poster_name'],
				'last_post_topic_id'   => $row['forum_last_post_id'],
				'last_post_topic_name' => $row['forum_last_post_subject'],
				'last_post_time'       => $row['forum_last_post_time']
			);
		}
		return $response->withJson($forums);
	}

	/**
	 * @return \Closure
	 */
	public function constructRoutes()
	{
		$self = $this;
		return function () use ($self) {
			/** @var \Slim\App $this */
			$this->get('/forums/{parentId}', [$self, 'boardList']);
			$this->get('/forums', [$self, 'boardList']);
		};
	}

	/**
	 * @return string
	 */
	public static function getGroup()
	{
		return '/board';
	}
}
