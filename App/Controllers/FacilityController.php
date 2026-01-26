<?php

namespace App\Controllers;

use App\Plugins\Http\Response as Status;
use App\Plugins\Http\Exceptions;

class FacilityController extends BaseController
{

    public function index(): void
    {

        $ok = $this->db->executeQuery("
            SELECT
                f.id,
                f.name,
                f.created_at,
                l.id AS location_id,
                l.city,
                l.address,
                l.zip_code,
                l.country_code,
                l.phone_number
            FROM facilities f
            JOIN locations l ON l.id = f.location_id
            ORDER BY f.id ASC
        ");

        if (!$ok) {

            (new Status\BadRequest(['error' => 'Failed to fetch facilities']))->send();
            return;
        }

        $rows = $this->db->fetchAll();


        $result = [];
        foreach ($rows as $row) {
            $okTags = $this->db->executeQuery("
                SELECT t.id, t.name
                FROM facility_tag ft
                JOIN tags t ON t.id = ft.tag_id
                WHERE ft.facility_id = :facility_id
                ORDER BY t.name ASC
            ", [
                ':facility_id' => (int)$row['id']
            ]);

            $tags = $okTags ? $this->db->fetchAll() : [];

            $result[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'created_at' => $row['created_at'],
                'location' => [
                    'id' => (int)$row['location_id'],
                    'city' => $row['city'],
                    'address' => $row['address'],
                    'zip_code' => $row['zip_code'],
                    'country_code' => $row['country_code'],
                    'phone_number' => $row['phone_number'],
                ],
                'tags' => $tags,
            ];
        }

        (new Status\Ok($result))->send();
    }


    public function show(int $id): void
    {
        $ok = $this->db->executeQuery("
            SELECT
                f.id,
                f.name,
                f.created_at,
                l.id AS location_id,
                l.city,
                l.address,
                l.zip_code,
                l.country_code,
                l.phone_number
            FROM facilities f
            JOIN locations l ON l.id = f.location_id
            WHERE f.id = :id
            LIMIT 1
        ", [
            ':id' => $id
        ]);

        if (!$ok) {
            (new Status\BadRequest(['error' => 'Failed to fetch facility']))->send();
            return;
        }

        $row = $this->db->fetch();

        if (!$row) {

            throw new Exceptions\NotFound(['error' => 'Facility not found']);
        }

        $okTags = $this->db->executeQuery("
            SELECT t.id, t.name
            FROM facility_tag ft
            JOIN tags t ON t.id = ft.tag_id
            WHERE ft.facility_id = :facility_id
            ORDER BY t.name ASC
        ", [
            ':facility_id' => (int)$row['id']
        ]);

        $tags = $okTags ? $this->db->fetchAll() : [];

        $facility = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'created_at' => $row['created_at'],
            'location' => [
                'id' => (int)$row['location_id'],
                'city' => $row['city'],
                'address' => $row['address'],
                'zip_code' => $row['zip_code'],
                'country_code' => $row['country_code'],
                'phone_number' => $row['phone_number'],
            ],
            'tags' => $tags,
        ];

        (new Status\Ok($facility))->send();
    }


    public function store(): void
    {

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true) ?? [];
        } else {
            $data = $_POST;
        }


        $name = trim((string)($data['name'] ?? ''));
        $locationId = (int)($data['location_id'] ?? 0);
        $tagsInput = $data['tags'] ?? [];


        if (is_string($tagsInput)) {
            $tagsInput = array_filter(array_map('trim', explode(',', $tagsInput)));
        }
        if (!is_array($tagsInput)) {
            $tagsInput = [];
        }


        $tags = [];
        foreach ($tagsInput as $t) {
            $t = trim((string)$t);
            if ($t !== '') $tags[] = $t;
        }
        $tags = array_values(array_unique($tags));


        if ($name === '' || $locationId <= 0) {
            (new Status\BadRequest([
                'error' => 'Validation failed',
                'fields' => [
                    'name' => 'required (string)',
                    'location_id' => 'required (number > 0)',
                ],
            ]))->send();
            return;
        }


        $okLoc = $this->db->executeQuery(
            "SELECT id FROM locations WHERE id = :id LIMIT 1",
            [':id' => $locationId]
        );

        if (!$okLoc || !$this->db->fetch()) {
            (new Status\BadRequest(['error' => 'Invalid location_id']))->send();
            return;
        }


        $this->db->beginTransaction();

        try {

            $okInsertFacility = $this->db->executeQuery(
                "INSERT INTO facilities (name, location_id) VALUES (:name, :location_id)",
                [
                    ':name' => $name,
                    ':location_id' => $locationId,
                ]
            );

            if (!$okInsertFacility) {
                $this->db->rollBack();
                (new Status\BadRequest(['error' => 'Failed to create facility']))->send();
                return;
            }

            $facilityId = (int)$this->db->getLastInsertedId();


            foreach ($tags as $tagName) {
                $tagId = $this->getOrCreateTagId($tagName);

                $okLink = $this->db->executeQuery(
                    "INSERT INTO facility_tag (facility_id, tag_id) VALUES (:facility_id, :tag_id)",
                    [
                        ':facility_id' => $facilityId,
                        ':tag_id' => $tagId,
                    ]
                );

                if (!$okLink) {
                    $this->db->rollBack();
                    (new Status\BadRequest(['error' => 'Failed to link tag to facility']))->send();
                    return;
                }
            }

            $this->db->commit();


            $this->respondWithFacility($facilityId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            (new Status\BadRequest([
                'error' => 'Unexpected error creating facility',
                'details' => $e->getMessage(),
            ]))->send();
        }
    }


    public function update(int $id): void
    {

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true) ?? [];
        } else {
            $data = $_POST;
        }


        $nameProvided = array_key_exists('name', $data);
        $locationProvided = array_key_exists('location_id', $data);
        $tagsProvided = array_key_exists('tags', $data);


        if (!$nameProvided && !$locationProvided && !$tagsProvided) {
            (new Status\BadRequest([
                'error' => 'No fields provided to update',
                'hint' => 'Provide at least one of: name, location_id, tags'
            ]))->send();
            return;
        }


        $okExists = $this->db->executeQuery("SELECT id FROM facilities WHERE id = :id LIMIT 1", [':id' => $id]);
        if (!$okExists || !$this->db->fetch()) {
            throw new Exceptions\NotFound(['error' => 'Facility not found']);
        }

        $name = trim((string)($data['name'] ?? ''));
        $locationId = (int)($data['location_id'] ?? 0);
        $tagsInput = $data['tags'] ?? [];


        if ($locationProvided) {
            if ($locationId <= 0) {
                (new Status\BadRequest(['error' => 'location_id must be > 0']))->send();
                return;
            }

            $okLoc = $this->db->executeQuery("SELECT id FROM locations WHERE id = :id LIMIT 1", [':id' => $locationId]);
            if (!$okLoc || !$this->db->fetch()) {
                (new Status\BadRequest(['error' => 'Invalid location_id']))->send();
                return;
            }
        }


        $tags = [];
        if ($tagsProvided) {
            if (is_string($tagsInput)) {
                $tagsInput = array_filter(array_map('trim', explode(',', $tagsInput)));
            }
            if (!is_array($tagsInput)) {
                $tagsInput = [];
            }

            foreach ($tagsInput as $t) {
                $t = trim((string)$t);
                if ($t !== '') $tags[] = $t;
            }
            $tags = array_values(array_unique($tags));
        }


        $this->db->beginTransaction();

        try {

            if ($nameProvided) {
                if ($name === '') {
                    $this->db->rollBack();
                    (new Status\BadRequest(['error' => 'name cannot be empty']))->send();
                    return;
                }

                $okUpdateName = $this->db->executeQuery(
                    "UPDATE facilities SET name = :name WHERE id = :id",
                    [':name' => $name, ':id' => $id]
                );

                if (!$okUpdateName) {
                    $this->db->rollBack();
                    (new Status\BadRequest(['error' => 'Failed to update name']))->send();
                    return;
                }
            }


            if ($locationProvided) {
                $okUpdateLoc = $this->db->executeQuery(
                    "UPDATE facilities SET location_id = :location_id WHERE id = :id",
                    [':location_id' => $locationId, ':id' => $id]
                );

                if (!$okUpdateLoc) {
                    $this->db->rollBack();
                    (new Status\BadRequest(['error' => 'Failed to update location_id']))->send();
                    return;
                }
            }


            if ($tagsProvided) {

                $okClear = $this->db->executeQuery(
                    "DELETE FROM facility_tag WHERE facility_id = :facility_id",
                    [':facility_id' => $id]
                );

                if (!$okClear) {
                    $this->db->rollBack();
                    (new Status\BadRequest(['error' => 'Failed to clear existing tags']))->send();
                    return;
                }


                foreach ($tags as $tagName) {
                    $tagId = $this->getOrCreateTagId($tagName);

                    $okLink = $this->db->executeQuery(
                        "INSERT INTO facility_tag (facility_id, tag_id) VALUES (:facility_id, :tag_id)",
                        [':facility_id' => $id, ':tag_id' => $tagId]
                    );

                    if (!$okLink) {
                        $this->db->rollBack();
                        (new Status\BadRequest(['error' => 'Failed to link tag to facility']))->send();
                        return;
                    }
                }
            }

            $this->db->commit();


            $this->respondWithFacility($id);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            (new Status\BadRequest([
                'error' => 'Unexpected error updating facility',
                'details' => $e->getMessage(),
            ]))->send();
        }
    }


    private function getOrCreateTagId(string $tagName): int
    {

        $ok = $this->db->executeQuery(
            "SELECT id FROM tags WHERE name = :name LIMIT 1",
            [':name' => $tagName]
        );

        if ($ok) {
            $row = $this->db->fetch();
            if ($row && isset($row['id'])) {
                return (int)$row['id'];
            }
        }


        $okInsert = $this->db->executeQuery(
            "INSERT INTO tags (name) VALUES (:name)",
            [':name' => $tagName]
        );

        if ($okInsert) {
            return (int)$this->db->getLastInsertedId();
        }


        $this->db->executeQuery(
            "SELECT id FROM tags WHERE name = :name LIMIT 1",
            [':name' => $tagName]
        );

        $row = $this->db->fetch();
        if ($row && isset($row['id'])) {
            return (int)$row['id'];
        }

        throw new \RuntimeException("Could not create or retrieve tag: {$tagName}");
    }


    private function respondWithFacility(int $id): void
    {
        $ok = $this->db->executeQuery("
            SELECT
                f.id,
                f.name,
                f.created_at,
                l.id AS location_id,
                l.city,
                l.address,
                l.zip_code,
                l.country_code,
                l.phone_number
            FROM facilities f
            JOIN locations l ON l.id = f.location_id
            WHERE f.id = :id
            LIMIT 1
        ", [':id' => $id]);

        if (!$ok) {
            (new Status\BadRequest(['error' => 'Failed to fetch facility']))->send();
            return;
        }

        $row = $this->db->fetch();

        if (!$row) {
            throw new Exceptions\NotFound(['error' => 'Facility not found']);
        }

        $okTags = $this->db->executeQuery("
            SELECT t.id, t.name
            FROM facility_tag ft
            JOIN tags t ON t.id = ft.tag_id
            WHERE ft.facility_id = :facility_id
            ORDER BY t.name ASC
        ", [':facility_id' => (int)$row['id']]);

        $tags = $okTags ? $this->db->fetchAll() : [];

        $facility = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'created_at' => $row['created_at'],
            'location' => [
                'id' => (int)$row['location_id'],
                'city' => $row['city'],
                'address' => $row['address'],
                'zip_code' => $row['zip_code'],
                'country_code' => $row['country_code'],
                'phone_number' => $row['phone_number'],
            ],
            'tags' => $tags,
        ];

        (new Status\Ok($facility))->send();
    }


    public function delete(int $id): void
    {

        $okExists = $this->db->executeQuery(
            "SELECT id FROM facilities WHERE id = :id LIMIT 1",
            [':id' => $id]
        );

        if (!$okExists || !$this->db->fetch()) {

            throw new Exceptions\NotFound(['error' => 'Facility not found']);
        }


        $this->db->beginTransaction();

        try {

            $okLinks = $this->db->executeQuery(
                "DELETE FROM facility_tag WHERE facility_id = :facility_id",
                [':facility_id' => $id]
            );

            if (!$okLinks) {
                $this->db->rollBack();
                (new Status\BadRequest(['error' => 'Failed to delete facility tags']))->send();
                return;
            }


            $okFacility = $this->db->executeQuery(
                "DELETE FROM facilities WHERE id = :id",
                [':id' => $id]
            );

            if (!$okFacility) {
                $this->db->rollBack();
                (new Status\BadRequest(['error' => 'Failed to delete facility']))->send();
                return;
            }


            $this->db->commit();


            (new Status\Ok([
                'message' => 'Facility deleted successfully',
                'id' => $id
            ]))->send();
        } catch (\Throwable $e) {

            $this->db->rollBack();

            (new Status\BadRequest([
                'error' => 'Unexpected error deleting facility',
                'details' => $e->getMessage(),
            ]))->send();
        }
    }


    public function search(): void
    {

        $name = trim((string)($_GET['name'] ?? ''));
        $tag  = trim((string)($_GET['tag'] ?? ''));
        $city = trim((string)($_GET['city'] ?? ''));


        if ($name === '' && $tag === '' && $city === '') {
            (new Status\BadRequest([
                'error' => 'Provide at least one search parameter: name, tag, or city',
                'example' => '/facilities/search?name=neo&city=ams&tag=veg'
            ]))->send();
            return;
        }


        $sql = "
        SELECT DISTINCT
            f.id,
            f.name,
            f.created_at,
            l.id AS location_id,
            l.city,
            l.address,
            l.zip_code,
            l.country_code,
            l.phone_number
        FROM facilities f
        JOIN locations l ON l.id = f.location_id
        LEFT JOIN facility_tag ft ON ft.facility_id = f.id
        LEFT JOIN tags t ON t.id = ft.tag_id
        WHERE 1=1
    ";

        $bind = [];

        // Partial match filters using LIKE
        if ($name !== '') {
            $sql .= " AND f.name LIKE :name ";
            $bind[':name'] = '%' . $name . '%';
        }

        if ($city !== '') {
            $sql .= " AND l.city LIKE :city ";
            $bind[':city'] = '%' . $city . '%';
        }

        if ($tag !== '') {
            // When tag filter is present, this effectively searches tag names too
            $sql .= " AND t.name LIKE :tag ";
            $bind[':tag'] = '%' . $tag . '%';
        }

        $sql .= " ORDER BY f.id ASC ";


        $ok = $this->db->executeQuery($sql, $bind);

        if (!$ok) {
            (new Status\BadRequest(['error' => 'Search query failed']))->send();
            return;
        }

        $rows = $this->db->fetchAll();


        foreach ($rows as $row) {
            $okTags = $this->db->executeQuery("
            SELECT t.id, t.name
            FROM facility_tag ft
            JOIN tags t ON t.id = ft.tag_id
            WHERE ft.facility_id = :facility_id
            ORDER BY t.name ASC
        ", [
                ':facility_id' => (int)$row['id']
            ]);

            $tags = $okTags ? $this->db->fetchAll() : [];

            $result[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'created_at' => $row['created_at'],
                'location' => [
                    'id' => (int)$row['location_id'],
                    'city' => $row['city'],
                    'address' => $row['address'],
                    'zip_code' => $row['zip_code'],
                    'country_code' => $row['country_code'],
                    'phone_number' => $row['phone_number'],
                ],
                'tags' => $tags,
            ];
        }

        (new Status\Ok($result))->send();
    }
}
