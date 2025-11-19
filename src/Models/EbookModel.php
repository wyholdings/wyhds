<?php

namespace App\Models;

use App\Database;
use PDO;

class EbookModel
{
    //Ebook list
    public function getAll()
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("SELECT * FROM ebooks ORDER BY created_at DESC");
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function upload($data)
    {
        $db = Database::getInstance()->getConnection();

        $stmt = $db->prepare("INSERT INTO ebooks (file_name, folder_name) VALUES (?, ?)");
        $stmt->execute([$data['file_name'], $data['folder_name']]);
    }

    /**
     * 특정 ebook_id에 대한 링크 전체 조회
     * return 형태:
     * [
     *   ['page' => 1, 'x' => 100, 'y' => 200, 'w' => 300, 'h' => 80,
     *    'target_type' => 'url', 'target' => 'https://...', 'title' => '...'],
     *   ...
     * ]
     */
    public function getLinksByEbook(string $ebookId): array
    {
        $db = Database::getInstance()->getConnection();

        $sql = "
            SELECT
                id,
                ebook_id,
                page,
                x, y, w, h,
                target_type,
                target,
                title
            FROM ebook_links
            WHERE ebook_id = :ebook_id
            ORDER BY page ASC, id ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(['ebook_id' => $ebookId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 링크 전체를 통째로 갈아끼우기 (관리자 모드에서 저장)
     *
     * $linkMap 형태 예시:
     * [
     *   1 => [
     *     ['href' => 'https://aaa.com', 'goto' => null, 'x' => 100, 'y' => 200, 'w' => 300, 'h' => 80, 'title' => ''],
     *   ],
     *   2 => [
     *     ['href' => null, 'goto' => 10, 'x' => 50, 'y' => 50, 'w' => 200, 'h' => 100, 'title' => '10페이지로 이동'],
     *   ],
     * ]
     */
    public function replaceLinks(string $ebookId, array $linkMap): bool
    {
        $db = Database::getInstance()->getConnection();
        
        $db->beginTransaction();

        try {
            // 1) 기존 링크 싹 삭제
            $del = $db->prepare("DELETE FROM ebook_links WHERE ebook_id = :ebook_id");
            $del->execute(['ebook_id' => $ebookId]);

            // 2) 새 데이터 insert
            $sql = "
                INSERT INTO ebook_links
                    (ebook_id, page, x, y, w, h, target_type, target, title)
                VALUES
                    (:ebook_id, :page, :x, :y, :w, :h, :target_type, :target, :title)
            ";
            $ins = $this->db->prepare($sql);

            foreach ($linkMap as $page => $areas) {
                $page = (int)$page;
                if (!is_array($areas)) continue;

                foreach ($areas as $area) {
                    $href = $area['href'] ?? null;
                    $goto = $area['goto'] ?? null;
                    $x    = (int)($area['x'] ?? 0);
                    $y    = (int)($area['y'] ?? 0);
                    $w    = (int)($area['w'] ?? 0);
                    $h    = (int)($area['h'] ?? 0);
                    $title= $area['title'] ?? '';

                    if ($href) {
                        $targetType = 'url';
                        $target     = $href;
                    } elseif ($goto) {
                        $targetType = 'page';
                        $target     = (string)(int)$goto;
                    } else {
                        // href/goto 둘 다 없으면 스킵
                        continue;
                    }

                    $ins->execute([
                        'ebook_id'    => $ebookId,
                        'page'        => $page,
                        'x'           => $x,
                        'y'           => $y,
                        'w'           => $w,
                        'h'           => $h,
                        'target_type' => $targetType,
                        'target'      => $target,
                        'title'       => $title,
                    ]);
                }
            }

            $this->db->commit();
            return true;

        } catch (\Throwable $e) {
            $this->db->rollBack();
            // 로그 찍어두면 좋음
            // error_log('[ebook_links] replace 실패: '.$e->getMessage());
            return false;
        }
    }
}
?>