<?php

/**
 * Project:            	CTRev
 * File:                spages.php
 *
 * @link 	  	http://ctrev.cyber-tm.com/
 * @copyright         	(c) 2008-2012, Cyber-Team
 * @author 	  	The Cheat <cybertmdev@gmail.com>
 * @name 		Управление стат. страницами
 * @version           	1.00
 */
if (!defined('INSITE'))
    die("Remote access denied!");

class spages_man {

    /**
     * Инициализация модуля стат. страниц
     * @global lang $lang
     * @global array $POST
     * @return null
     */
    public function init() {
        global $lang, $POST;
        $lang->get('admin/static');
        $act = $_GET["act"];
        switch ($act) {
            case "save":
                $_POST['html'] = $POST['html'];
                $this->save($_POST);
                break;
            case "add":
            case "edit":
                $this->add($_GET['id']);
                break;
            default:
                $this->show();
                break;
        }
    }

    /**
     * Отображение списка стат. страниц
     * @global db $db
     * @global tpl $tpl
     * @return null
     */
    protected function show() {
        global $db, $tpl;
        $r = $db->query('SELECT * FROM static');
        $tpl->assign('res', $db->fetch2array($r));
        $tpl->display('admin/static/index.tpl');
    }

    /**
     * Добавление/редактирование стат. страницы
     * @global db $db
     * @global tpl $tpl
     * @param int $id ID стат. страницы
     * @return null
     */
    protected function add($id = null) {
        global $db, $tpl;
        $id = (int) $id;
        if ($id) {
            $r = $db->query('SELECT * FROM static WHERE id=' . $id . ' LIMIT 1');
            $tpl->assign("row", $db->fetch_assoc($r));
        }
        $tpl->assign("id", $id);
        $tpl->display('admin/static/add.tpl');
    }

    /**
     * Сохранение стат. страницы
     * @global db $db
     * @global furl $furl
     * @global string $admin_file
     * @param array $data массив данных
     * @return null
     * @throws EngineException 
     */
    protected function save($data) {
        global $db, $furl, $admin_file;
        $cols = array(
            'url',
            'title',
            'content',
            'bbcode');
        $update = rex($data, $cols);
        $id = (int) $data['id'];
        $update['bbcode'] = (bool) $update['bbcode'];
        if (!validword($update['url']))
            throw new EngineException('static_url_not_entered');
        if (!$update['title'])
            throw new EngineException('static_title_not_entered');
        if (!$update['bbcode'])
            $update['content'] = $data['html'];
        if (!$update['content'])
            throw new EngineException('static_content_not_entered');
        if (!$id) {
            $db->insert($update, 'static');
            log_add('added_static', 'admin', $data['url']);
        } else {
            $db->update($update, 'static', 'WHERE id=' . $id . ' LIMIT 1');
            log_add('changed_static', 'admin', $data['url']);
        }
        $furl->location($admin_file);
    }

}

class spages_man_ajax {

    /**
     * Инициализация AJAX-части модуля
     * @return null
     */
    public function init() {
        $act = $_GET["act"];
        $id = (int) $_POST["id"];
        switch ($act) {
            case "delete":
                $this->delete($id);
                break;
        }
        die("OK!");
    }

    /**
     * Удаление стат. страницы
     * @global db $db
     * @param int $id ID страницы
     * @return null
     */
    protected function delete($id) {
        global $db;
        $id = (int) $id;
        $db->delete('static', 'WHERE id=' . $id . ' LIMIT 1');
        log_add('deleted_static', 'admin', $id);
    }

}

?>