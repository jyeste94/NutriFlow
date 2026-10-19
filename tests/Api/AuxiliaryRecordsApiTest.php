<?php

namespace App\Tests\Api;

final class AuxiliaryRecordsApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->em->getConnection();
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS shopping_list_items (
            id CHAR(36) NOT NULL PRIMARY KEY, user_id CHAR(36) NOT NULL,
            food_name VARCHAR(255) NOT NULL, quantity VARCHAR(50), unit VARCHAR(50),
            checked INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS fasting_logs (
            id CHAR(36) NOT NULL PRIMARY KEY, user_id CHAR(36) NOT NULL,
            date DATE NOT NULL, start_time TIME, end_time TIME,
            duration_hours DECIMAL(5,2) DEFAULT 0, notes TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
        $connection->executeStatement('DELETE FROM shopping_list_items');
        $connection->executeStatement('DELETE FROM fasting_logs');
    }

    public function testShoppingChangesPersistOnceAndCannotAffectAnotherAccount(): void
    {
        $owner = $this->authHeaders('shopping-owner');
        $other = $this->authHeaders('shopping-other');
        $this->client->jsonRequest('POST', '/v1/shopping-list', ['food_name' => ' Avena '], $owner);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];
        $this->client->request('GET', '/v1/shopping-list', [], [], $owner);
        $this->assertCount(1, $this->jsonResponse());
        $this->assertSame($id, $this->jsonResponse()[0]['id']);
        $this->assertSame('Avena', $this->jsonResponse()[0]['food_name']);
        $this->assertFalse($this->jsonResponse()[0]['checked']);

        $this->client->jsonRequest('PUT', '/v1/shopping-list/'.$id, ['food_name' => 'Arroz'], $owner);
        $this->assertResponseIsSuccessful();
        $this->client->request('PATCH', '/v1/shopping-list/'.$id.'/toggle', [], [], $owner);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/shopping-list', [], [], $owner);
        $this->assertSame('Arroz', $this->jsonResponse()[0]['food_name']);
        $this->assertTrue($this->jsonResponse()[0]['checked']);

        $this->client->jsonRequest('PUT', '/v1/shopping-list/'.$id, ['food_name' => 'Otro'], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('PATCH', '/v1/shopping-list/'.$id.'/toggle', [], [], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/v1/shopping-list/'.$id, [], [], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/v1/shopping-list', [], [], $other);
        $this->assertSame([], $this->jsonResponse());
        $this->client->request('DELETE', '/v1/shopping-list/'.$id, [], [], $owner);
        $this->assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/v1/shopping-list', [], [], $owner);
        $this->assertSame([], $this->jsonResponse());
        $this->client->request('DELETE', '/v1/shopping-list/'.$id, [], [], $owner);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testFastingDatesAndDurationSurviveReloadAndEdit(): void
    {
        $owner = $this->authHeaders('fasting-owner');
        $other = $this->authHeaders('fasting-other');
        $record = ['date' => '2026-10-18', 'start_time' => '20:30', 'end_date' => '2026-10-19', 'end_time' => '12:15'];
        $this->client->jsonRequest('POST', '/v1/fasting', $record, $owner);
        $this->assertResponseStatusCodeSame(201);
        $id = $this->jsonResponse()['id'];
        $this->client->request('GET', '/v1/fasting', [], [], $owner);
        $this->assertCount(1, $this->jsonResponse());
        $this->assertSame($id, $this->jsonResponse()[0]['id']);
        $this->assertSame('2026-10-19', $this->jsonResponse()[0]['end_date']);
        $this->assertEquals(15.75, $this->jsonResponse()[0]['duration_hours']);

        $record['end_date'] = '2026-10-20';
        $record['end_time'] = '20:29';
        $this->client->jsonRequest('PUT', '/v1/fasting/'.$id, $record, $owner);
        $this->assertResponseIsSuccessful();
        $this->client->request('GET', '/v1/fasting', [], [], $owner);
        $this->assertSame('2026-10-20', $this->jsonResponse()[0]['end_date']);
        $this->assertEquals(47.98, $this->jsonResponse()[0]['duration_hours']);
        $this->client->jsonRequest('PUT', '/v1/fasting/'.$id, $record, $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', '/v1/fasting/'.$id, [], [], $other);
        $this->assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/v1/fasting', [], [], $other);
        $this->assertSame([], $this->jsonResponse());
        $this->client->request('DELETE', '/v1/fasting/'.$id, [], [], $owner);
        $this->assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/v1/fasting', [], [], $owner);
        $this->assertSame([], $this->jsonResponse());
    }

    public function testInvalidFastingIntervalsDoNotCreateRecords(): void
    {
        $headers = $this->authHeaders('fasting-validation');
        $valid = ['date' => '2026-10-18', 'start_time' => '20:00', 'end_date' => '2026-10-19', 'end_time' => '12:00'];
        $invalidChanges = [
            ['date' => '2026-02-30'],
            ['start_time' => "20:00\0"],
            ['end_date' => '2026-10-18', 'end_time' => '19:00'],
            ['end_date' => '2026-10-18', 'end_time' => '20:00'],
            ['end_date' => '2026-10-20', 'end_time' => '20:01'],
            ['duration_hours' => 20],
        ];
        foreach ($invalidChanges as $changes) {
            $this->client->jsonRequest('POST', '/v1/fasting', array_replace($valid, $changes), $headers);
            $this->assertResponseStatusCodeSame(400);
        }
        $this->client->request('GET', '/v1/fasting', [], [], $headers);
        $this->assertSame([], $this->jsonResponse());
    }
}
