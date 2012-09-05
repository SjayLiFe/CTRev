<?php

/**
 * Project:            	CTRev
 * File:                class.bittorrent.php
 *
 * @link 	  	http://ctrev.cyber-tm.ru/
 * @copyright         	(c) 2008-2012, Cyber-Team
 * @author 	  	The Cheat <cybertmdev@gmail.com>
 * @name		Класс для работы с торрент-файлами
 * @version           	1.00
 */
if (!defined('INSITE'))
    die('Remote access denied!');

class announce_parser extends fbenc {

    /**
     * Проверка URL-а аннонсера и возвращение частей URL-а
     * @param string $url URL аннонсера
     * @return array Matches из preg_match
     * 2 - протокол
     * 3 - домен
     * 4 - порт
     * 5 - оставшаяся часть
     */
    protected function check_announce($url) {
        preg_match('/^' . display::url_pattern . '$/siu', $url, $m);
        if ($m[2] == "ftp" || $m[3] == "retracker.local")
            return;
        return $m;
    }

    /**
     * Получение списка аннонсеров из торрента
     * @global config $config
     * @param array $dict словарь торрента
     * @return array список аннонсеров
     */
    public function announce_lists($dict) {
        global $config;
        $announce_urls = array();
        if ($config->v('multitracker_on'))
            if ($dict ['announce-list']) {
                foreach ($dict ['announce-list'] as $tv) {
                    $tv = $tv[0];
                    if ($tv && $this->check_announce($tv))
                        $announce_urls [] = $tv;
                }
            } elseif ($dict ['announce'])
                $announce_urls [] = $dict ['announce'];
        $announce_urls = array_unique($announce_urls);
        return $announce_urls;
    }

}

class bittorrent extends announce_parser {
    /**
     * Паттерн для именования файлов в зависимости от времени загрузки
     * @const string files_pattern
     */

    const files_pattern = 's%d_%de';
    /**
     * Префикс в имени торрент файла, хранимого на сервере
     * @const string torrent_prefix
     */
    const torrent_prefix = "t";

    /**
     * Получение значащей части имени файла, уникальный идентефикатор для каждого торрента
     * @param int $time время создания
     * @param int $poster_id ID создателя
     * @return string идентефикатор
     */
    public function get_filename($time, $poster_id) {
        $poster_id = (int) $poster_id;
        $time = (int) $time;
        return sprintf(self::files_pattern, $time, $poster_id);
    }

    /**
     * Замена passkey в URL трекера на настройки пользователя
     * @global users $users
     * @global display $display
     * @param string $url URL трекера
     * @param string|array строка из конфига
     * @return bool true, в случае успешной замены
     */
    protected function pk_replace(&$url, &$pk) {
        global $users, $display;
        if (!is_array($pk))
            $pk = preg_split('/\s+/', trim($pk));
        list($host, $pk_s, $pk_e) = $pk;
        if (!$host || !$pk_s)
            return;
        if (!preg_match('/^' . display::url_pattern . '$/siu', $url, $m))
            return;
        if (mb_strpos($m[3] . ':' . $m[4], $host) === false)
            return;
        $apk = $users->v('announce_pk');
        $url = preg_replace('/(' . mpc($pk_s) . ')(.*?)(' . ($pk_e ? mpc($pk_e) : "$") . ')/siu', '$1' .
                $apk[$host] . '$3', $url);
        return true;
    }

    /**
     * Предобработка dict перед скачиванием
     * @global config $config
     * @global users $users
     * @global furl $furl
     * @global lang $lang
     * @param int $id ID торрента
     * @param int $posted_time время постинга
     * @param int $poster_id ID автора
     * @return array словарь торрента
     */
    protected function prepare_dict($id, $posted_time, $poster_id) {
        global $config, $users, $furl, $lang;

        $fname = $this->get_filename($posted_time, $poster_id);
        $dict = $this->bdec(ROOT . $config->v('torrents_folder') . '/' . self::torrent_prefix . $fname . ".torrent", true);

        $dict ['comment'] = sprintf($lang->v('torrents_from_site'), $config->v('site_title'), $furl->construct("download", array(
                    "id" => $id,
                    'noencode' => true)));

        $passkey = $users->v('passkey');
        $dict ['announce'] = $config->v('annadress') ? $config->v('annadress') :
                $furl->construct('announce', array(
                    'passkey' => $passkey,
                    'noencode' => true), false, true);
        if ($config->v('get_pk') && $dict ['announce-list']) {
            if ($users->v('settings'))
                $users->decode_settings();
            if (!is_array($users->v('announce_pk')))
                $users->unserialize('announce_pk');
            $pk = explode("\n", $config->v('get_pk'));
            $c = count($pk);
            foreach ($dict ['announce-list'] as $k => $a)
                for ($i = 0; $i < $c; $i++)
                    if ($this->pk_replace($dict ['announce-list'][$k][0], $pk[$i]))
                        break;
        }
        if ($config->v('additional_announces')) {
            $add = explode("\n", $config->v('additional_announces'));
            foreach ($add as $a) {
                $a = trim($a);
                $a = array($a);
                if (in_array($a, $dict ['announce-list']))
                    continue;
                $dict ['announce-list'][] = $a;
            }
        }
        if ($dict ['announce-list'])
            array_unshift($dict ['announce-list'], array($dict ['announce']));
        return $dict;
    }

    /**
     * Скачивание торрент файла
     * @global db $db
     * @global users $users
     * @global lang $lang
     * @global etc $etc
     * @global uploader $uploader
     * @global plugins $plugins
     * @param int $id ID торрент файла
     * @return null
     * @throws EngineException 
     */
    public function download_torrent($id) {
        global $db, $users, $lang, $etc, $uploader, $plugins;

        $id = (int) $id;
        try {
            $users->check_perms('torrents');
            $r = $db->query('SELECT t.banned, t.poster_id, t.posted_time, t.price, d.uid FROM torrents AS t
            LEFT JOIN downloaded AS d ON d.tid=t.id AND d.uid=' . $users->v('id') . '
            WHERE t.id=' . $id . ' LIMIT 1');
            list($banned, $poster_id, $posted_time, $price, $downloaded) = $db->fetch_row($r);
            $lang->get('torrents');
            if (!$poster_id || $banned)
                throw new EngineException('torrents_no_this_torrents');
            $off = $users->perm('free', 2) ? 1 : ($users->perm('free', 1) ? 0.5 : 0);
            $price = $price * (1 - $off);
            
            if ($poster_id == $users->v('id'))
                $downloaded = true;

            $plugins->pass_data(array('price' => &$price,
                'id' => $id), true)->run_hook('torrents_download_price');

            if ($users->v('bonus_count') < $price)
                throw new EngineException('torrents_no_enough_bonus');
            if (!$downloaded) {
                if ($price)
                    $etc->add_res('bonus', -$price);
                $db->insert(array('tid' => $id, 'uid' => $users->v('id')), 'downloaded');
            }
            $dict = $this->prepare_dict($id, $posted_time, $poster_id);

            $plugins->pass_data(array('dict' => &$dict))->run_hook('torrents_download_dict');

            $name = 'id' . $id . '[' . $_SERVER["HTTP_HOST"] . '].torrent';
            $uploader->download_headers($this->benc($dict), $name, "application/x-bittorrent");
        } catch (PReturn $e) {
            return $e->r();
        }
    }

    /**
     * Проверка dict перед записью
     * @global config $config
     * @global furl $furl
     * @global users $users
     * @param string $t путь к файлу торрента
     * @param array $filelist список файлов
     * @param int $filesize размер файла
     * @param array $announce_list список аннонсеров
     * @return array массив из словаря и раздела info словаря
     * @throws EngineException 
     */
    protected function check_dict($t, &$filelist = null, &$filesize = null, &$announce_list = null) {
        global $config, $furl, $users;
        $dict = $this->bdec($t, true);
        if (!$dict)
            throw new EngineException('bencode_cant_parse_file');
        list($info) = $this->dict_check($dict, "info");
        list($dname, $plen, $pieces,
                $tlen, $flist) = $this->dict_check($info, "name(s):piece length(i):pieces(s):!length(i):!files(l)");
        if (strlen($pieces) % 20 != 0)
            throw new EngineException("bencode_invalid_key");
        if ($tlen) {
            $filelist [] = array($dname, $tlen);
            $filesize = $tlen;
        } else {
            if (!$flist || !is_array($flist) || count($flist) < 1)
                throw new EngineException("bencode_dict_miss_keys");
            $filesize = 0;
            foreach ($flist as $fn) {
                list ( $ll, $ff ) = $this->dict_check($fn, "length(i):path(l)");
                $filesize += $ll;
                $ffa = array();
                foreach ($ff as $ffe) {
                    if (!is_string($ffe))
                        throw new EngineException("bencode_wrong_filename");
                    $ffa [] = $ffe;
                }
                if (!count($ffa))
                    throw new EngineException("bencode_wrong_filename");
                $ffe = implode("/", $ffa);
                $filelist [] = array(
                    $ffe,
                    $ll);
            }
        }
        $filelist = serialize($filelist);
        $idict = &$dict ['info'];
        if ($config->v('DHT_on') == 0)
            $idict ['private'] = 1;
        elseif ($config->v('DHT_on') == 1)
            unset($idict ['private']);
        // не меняем, если -1

        $announce_list = $this->announce_lists($dict);
        $announce_list = serialize($announce_list);

        // удаляем излишки
        unset($dict ['nodes']);
        unset($idict ['crc32']);
        unset($idict ['ed2k']);
        unset($idict ['md5sum']);
        unset($idict ['sha1']);
        unset($idict ['tiger']);
        unset($dict ['azureus_properties']);

        $dict ['publisher.utf-8'] = $dict ['publisher'] = $dict ['created by'] = $users->v('username');
        $dict ['publisher-url.utf-8'] = $dict ['publisher-url'] = $furl->construct("users", array(
            "user" => $users->v('username'),
            'noencode' => true));
        return array($dict, $idict);
    }

    /**
     * Проверка и загрузка торрент-файла
     * @global file $file
     * @global uploader $uploader
     * @global config $config
     * @param string $id ID торрент-файла
     * @param string $filevar файловая переменная($_FILES) для торрента
     * @param string $filelist ссылка на сериализованный список файлов в торренте
     * @param int $filesize ссылка на размер файла
     * @param string $announce_list ссылка на аннонсеры для мультитрекера
     * @return string инфохеш торрент файла
     */
    public function torrent_file($id, $filevar, &$filelist = null, &$filesize = null, &$announce_list = null) {
        global $file, $uploader, $config;
        ref($uploader)->check($filevar, /* ссылка */ $tmp = 'torrents');
        $t = $filevar["tmp_name"];

        list($dict, $idict) = $this->check_dict($t, $filelist, $filesize, $announce_list);

        $file->write_file($this->benc($dict), $config->v('torrents_folder') . '/' . self::torrent_prefix . $id . ".torrent");

        return sha1($this->benc($idict));
    }

}

?>