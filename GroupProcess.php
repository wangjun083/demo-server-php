<?php
/**
* 获取我加入的群
* @UserFunction(method = GET)
* @CheckLogin
*/
function get_my_group() {
	$db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
	$group_list = $db->fetchAll('SELECT `group`.`id`, `group`.`name`,`group_user`.`timestamp` AS `join_date`  FROM `group_user` INNER JOIN `group` ON `group_user`.`group_id` = `group`.`id` WHERE `group_user`.`user_id` = ?', getCurrentUserId());

	return $group_list;
}

/**
* 创建群
* @UserFunction(method = POST)
* @CheckLogin
*/
function create_group(String $name, String $introduce ) {
	$db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
	$user_id = getCurrentUserId();
	$group_id = $db->insert('INSERT INTO `group`(`name`,`introduce`,`create_user_id`) VALUES(?,?,?);', $name, $introduce, $user_id);
	$db->exec('INSERT INTO `group_user`(`group_id`, `user_id`, `role`) VALUES(?,?,1);', $group_id, $user_id);

	return $group_id;
}

/**
 * 创建群,并添加一批用户,users格式为 "uid1,uid2,uid3",即uid用,分隔
 * @UserFunction(method = POST)
 * @CheckLogin
 */
function create_uses_group(String $name, String $introduce ,String $users) {
    $group_id = create_group($name,$introduce);
    if (!empty($users->val)) {
        batch_group( $group_id,  $users);
    }

    return $group_id;
}

/**
* 在数据库和融云服务器创建群,users格式为 "uid1,uid2,uid3",即uid用,分隔
* @UserFunction(method = POST)
* @CheckLogin
*/
function create_group_remote(String $name, String $introduce ,String $users) {
    $group_id = create_group( $name,  $introduce ) ;
    $user_id = getCurrentUserId();
    $result = ServerAPI::getInstance()->groupCreate($user_id,$group_id,$name);
    if (!empty($users->val)) {
        $group_id = new Integer($group_id);
        batch_group_remote( $group_id,  $users);
    }

    return $group_id;
}
/**
* 更新群信息
* @UserFunction(method = POST)
* @CheckLogin
*/
function update_group(Integer $id, String $name, String $introduce) {
	$db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
	$user_id = getCurrentUserId();
	if($db->fetchColumn('SELECT `create_user_id` FROM `group` WHERE `id`=?',$id)==$user_id){
		$db->exec('UPDATE `group` SET `name`=?,`introduce`=? WHERE `id`=?;', $name, $introduce, $id);
	} else {
		throw new ProException('you are not this group admin', 204);
	}
}

/**
 * 批量添加用户,users格式为 "uid1,uid2,uid3",即uid用,分隔
 * @UserFunction(method = POST)
 * @CheckLogin
 */
function batch_group(Integer $group_id, String $users){
    $user_array = explode(',', $users);
    if (count($user_array)==0) {
        throw new ProException('add at least one group user', 205);
    }
    $user_id = getCurrentUserId();
    $db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
    if($db->fetchColumn('SELECT `create_user_id` FROM `group` WHERE `id`=?',$group_id)!==$user_id){
        throw new ProException('you are not this group admin', 204);
    }
    //没有检测群中重复用户和用户表中不存在的id
    $param_arr[0] = 'INSERT INTO `group_user`(`group_id`, `user_id`, `role`) VALUES';
    foreach ($user_array as $i=>$user_item){
        $param_arr[0] .= '('.$group_id.',?,1),';
        $param_arr[$i+1] = $user_item;
    }
    $param_arr[0] = substr($param_arr[0], 0,-1);
    $param_arr[0] .=';';
    $result = call_user_func_array(array($db,'exec'), $param_arr);
    return $result;
}

/**
 * 在本地数据库和融云批量添加用户,users格式为 "uid1,uid2,uid3",即uid用,分隔
 * @UserFunction(method = POST)
 * @CheckLogin
 */
function batch_group_remote(Integer $group_id, String $users){
    $result_local = batch_group($group_id,$users);
    if (!$result_local) {
        throw new ProException('batch insert failure', 206);
    }
    $db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
    $groupName = $db->fetchColumn('SELECT `name` FROM `group` WHERE `id`=?',$group_id);
    $userId = explode(',', $users);
    $result = ServerAPI::getInstance()->groupJoin($userId,$group_id,$groupName);
}

/**
* 加入群
* @UserFunction(method = GET|POST)
* @CheckLogin
*/
function join_group(Integer $id) {
	$db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
	$user_id = getCurrentUserId();
	$group = $db->fetch('SELECT * FROM `group`  WHERE `id` = ?;', $id);
	if ($group) {
		if ($group['number'] >= $group['max_number']){
			throw new ProException('group $id reach limit', 202);
		}

		if ($db->fetchColumn('SELECT count(*) FROM `group_user` WHERE `user_id`=? AND `group_id`=?', $user_id, $id)!=0) {
			throw new ProException('you have to join the $id group', 203);
		}

		$db->exec('INSERT INTO `group_user`(`group_id`, `user_id`, `role`) VALUES(?,?,0);', $id, $user_id);
		$db->exec('UPDATE `group` SET `number` = `number`+1  WHERE `id` = ?;', $id);
	} else {
		throw new ProException('group $id is not exists', 201);
	}
}

/**
* 退出群
* @UserFunction(method = GET|POST)
* @CheckLogin
*/
function quit_group(Integer $id) {
	$db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
	$user_id = getCurrentUserId();
	if ($db->exec('DELETE FROM `group_user` WHERE `group_id` = ? AND `user_id` = ?', $id, $user_id)>0){
		$db->exec('UPDATE `group` SET `number` = `number`-1  WHERE `id` = ?;', $id);
	}
}

/**
* 获取指定群信息
* @UserFunction(method = GET)
* @CheckLogin
*/
function get_group(Integer $id) {
	$db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
	$user_id = getCurrentUserId();
	$group = $db->fetch('SELECT * FROM `group` WHERE `id` = ?', $id);
	$group['users'] = $db->fetchAll('SELECT a.`id`, a.`username`, a.`portrait` FROM `user` AS a INNER JOIN `group_user` AS b ON  b.user_id=a.id WHERE b.group_id = ?' ,$id);

	return $group;
}

/**
* 获取全部群信息
* @UserFunction(method = GET)
*/
function get_all_group() {
	$db = new DataBase(DB_DNS, DB_USER, DB_PASSWORD);
	$group_list = $db->fetchAll('SELECT * FROM `group`');

	return $group_list;
}

/**
* 获取聊天室信息
* @UserFunction(method = GET)
*/
function get_all_chatroom() {
	$group_list = array("chatroom_01"=>"聊天室一","chatroom_02"=>"聊天室二","chatroom_03"=>"聊天室三","chatroom_04"=>"聊天室四","chatroom_05"=>"聊天室五");

	return $group_list;
}