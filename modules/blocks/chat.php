<?php

/**
 * Project:             CTRev
 * @file                modules/blocks/chat.php
 *
 * @page 	  	http://ctrev.cyber-tm.ru/
 * @copyright           (c) 2008-2012, Cyber-Team
 * @author 	  	The Cheat <cybertmdev@gmail.com>
 * @name 		Блок чата
 * @version             1.00
 */
if (!defined('INSITE'))
    die("Remote access denied!");

class chat_block {

    /**
     * Инициализация чата
     * @return null
     */
    public function init() {
        if (!users::o()->perm('chat'))
            return;
        if (!config::o()->mstate('chat'))
            return;
        lang::o()->get("blocks/chat");
        tpl::o()->display('chat/index.tpl');
    }

}

?>