<?php

//include(__DIR__ . '/Big52003.php');
ini_set('memory_limit', '2048m');
date_default_timezone_set('Asia/Taipei');

class Updater
{
    public function getDownloadLink($url)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_COOKIEFILE, 'tmp');
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.63 Safari/537.36');
        $content = curl_exec($curl);
        $doc = new DOMDocument;
        @$doc->loadHTML($content);
        $form_dom = $doc->getElementById('form1');
        $params = array();
        foreach ($doc->getElementsByTagName('input') as $input_dom) {
            if ($input_dom->getAttribute('type') == 'hidden') {
                $params[] = urlencode($input_dom->getAttribute('name')) . '=' . urlencode(htmlspecialchars_decode($input_dom->getAttribute('value')));
            }
        }
        $params[] = 'WUCTGOS_MAPDataDownload1%24gvFreeFileList%24ctl02%24ImageButton1.x=21';
        $params[] = 'WUCTGOS_MAPDataDownload1%24gvFreeFileList%24ctl02%24ImageButton1.y=1';

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_COOKIEFILE, 'tmp');
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.63 Safari/537.36');
        curl_setopt($curl, CURLOPT_REFERER, $url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, implode('&', $params));
        $content = curl_exec($curl);

        $info = curl_getinfo($curl);
        if ($info['http_code'] == 302) {
            return $info["redirect_url"] . "\n";
        }
    }

    public function downloadFromURL($file_url, $folder, $origin_source_srs, $encoding)
    {
        $url = 'http://shp2json.ronny.tw/api/downloadurl?url=' . urlencode($file_url);
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $ret = curl_exec($curl);
        if (!$ret = json_decode($ret) or $ret->error) {
            throw new Exception("下載 {$file_url} 失敗: " . $ret->message);
        }

        $url = $ret->getshp_api;
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $ret = curl_exec($curl);
        if (!$ret = json_decode($ret) or $ret->error) {
            throw new Exception("取得 {$file_url} shp 列表失敗: " . $ret->message);
        }

        $target_dir = __DIR__ . '/../geo/' . $folder;
        if (!file_exists($target_dir)) {
            mkdir($target_dir);
        }
        foreach ($ret->data as $shpfile) {
            $source_srs = $origin_source_srs;
            if ($origin_source_srs == 'twd97/???') {
                if (preg_match('#(...)\.shp#', $shpfile->geojson_api, $matches)) {
                    $source_srs = 'twd97/' . $matches[1];
                } 
            }
            $url = $shpfile->geojson_api . '&source_srs=' . urlencode($source_srs);
            error_log($url);
            $curl = curl_init($url);
            $download_fp = tmpfile();
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_FILE, $download_fp);
            curl_exec($curl);
            curl_close($curl);
            fflush($download_fp);

            $file_name = preg_replace_callback('/#U([0-9a-f]*)/', function($e){
                return mb_convert_encoding('&#' . hexdec($e[1]) . ';','UTF-8', 'HTML-ENTITIES');
            }, substr($shpfile->file, 0, -4));
            $target_file = $target_dir . '/' . $file_name . '.json';
            if (!file_exists(dirname($target_file))) {
                mkdir(dirname($target_file), 0777, true);
            }

            if (strtolower($encoding) == 'big5') {
                $cmd = ("env LC_ALL=C sed -i " . escapeshellarg('s/\([\x81-\xFE]\\\\\)\\\\/\1/g') . ' ' . escapeshellarg(stream_get_meta_data($download_fp)['uri']));
                exec($cmd);
                exec("piconv -f Big5 < " . escapeshellarg(stream_get_meta_data($download_fp)['uri']) . ' > ' . escapeshellarg($target_file));
            } else {
                rename(stream_get_meta_data($download_fp)['uri'], $target_file);
            }

            $cmd = "node " . escapeshellarg(__DIR__ . '/geojson_parse.js') . " get_type " . escapeshellarg($target_file);
            exec($cmd, $outputs, $ret);
            if ($ret) {
                throw new Exception("取得 {$file_url} JSON 格式錯誤: " . $ret->message);
            }
        }
    }

    public function main($argv)
    {
        $fp = fopen(__DIR__ . '/../geo.csv', 'r');
        $fp_new = fopen(__DIR__ . '/../geo_new.csv', 'w');
        $columns = fgetcsv($fp);
        fputcsv($fp_new, $columns);

        while ($row = fgetcsv($fp)) {
            list($name, $url, $srs, $origin_encoding, $updated_at) = $row;
            if ($updated_at) {
                fputcsv($fp_new, array($name, $url, $srs, $origin_encoding, $updated_at));
                continue;
            }

            $download_link = trim($this->getDownloadLink($url));
            error_log($download_link);
            $this->downloadFromURL($download_link, $name, $srs, $origin_encoding);
            $updated_at = date('Y/m/d H:i:s');

            fputcsv($fp_new, array($name, $url, $srs, $origin_encoding, $updated_at));
        }
        fclose($fp_new);
        rename(__DIR__ . '/../geo_new.csv', __DIR__ . '/../geo.csv');
    }
}

$u = new Updater;
$u->main($_SERVER['argv']);
