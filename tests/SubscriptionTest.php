<?php

require_once dirname(__DIR__) . "/event/lib/subscription.php";
require_once __DIR__ . "/conftest.php";

use PHPUnit\Framework\TestCase;

class SubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        // Set up your database and other configurations here.
        // You can migrate tables, clean the database, etc., as needed.
        clean_db();
        migrate_all();
    }

    /**
     * @return array<int, Person>
     */
    private function getData(int $n_samples = 1): array
    {
        $max_rows = range(1, $n_samples);
        $result = [];

        foreach ($max_rows as $index) {
            $fullname = "test" . $index;
            $email = "test@test.com" . $index;
            $phone = "111111111" . $index;

            $person = new Person();
            $person->fullname = $fullname;
            $person->email = $email;
            $person->phone = $phone;
            $person->save();

            $person = Person::get([
                "fullname" => $fullname,
                "email" => $email,
                "phone" => $phone,
            ]);
            $this->assertGreaterThan(0, $person->id);
            $result[] = $person;
        }

        return $result;
    }

    public function testGetSubscription(): void
    {
        $data = $this->getData();
        $person = $data[0];

        $result = Subscription::get([
            "person_id" => $person->id,
        ]);
        $this->assertNull($result);

        $subscription = new Subscription();
        $subscription->person = $person;
        $subscription->qr = "asdf";
        $subscription->save();

        $result = Subscription::get([
            "person_id" => $person->id,
        ]);

        $this->assertNotNull($result);
        $this->assertInstanceOf('Subscription', $result);
        $this->assertEquals($person->fullname, $result->person->fullname);
        $this->assertEquals($person->email, $result->person->email);
        $this->assertEquals($person->phone, $result->person->phone);
        $this->assertEquals(1, $result->id);
    }

    public function testGetSubscriptionById(): void
    {
        $data = $this->getData();
        $person = $data[0];

        $subscription = new Subscription();
        $subscription->person = $person;
        $subscription->qr = "sdf";
        $subscription->save();

        $result = Subscription::get([
            "id" => 1,
        ]);

        $this->assertNotNull($result);
        $this->assertInstanceOf('Subscription', $result);
        $this->assertEquals($person->fullname, $result->person->fullname);
        $this->assertEquals($person->email, $result->person->email);
        $this->assertEquals($person->phone, $result->person->phone);
        $this->assertEquals(1, $result->id);
    }

    public function testListSubscriptions(): void
    {
        $n_samples = 5;
        $data = $this->getData($n_samples);
        $people = $data;

        $result = Subscription::list();
        $this->assertCount(0, $result);

        $max_rows = range(1, $n_samples);

        foreach ($max_rows as $index) {
            $subscription = new Subscription();
            $subscription->person = $people[$index - 1];
            $subscription->qr = "asdf";
            $subscription->save();
        }

        $result = Subscription::list();

        $this->assertIsArray($result);

        // Iterate over the list of Subscription objects
        foreach ($result as $subscription) {
            $this->assertInstanceOf('Subscription', $subscription);

            $person_ind = $subscription->id - 1;
            $suffix = $subscription->id;

            $this->assertNotNull($subscription);
            $this->assertEquals($people[$person_ind]->fullname, $subscription->person->fullname);
            $this->assertNotNull($subscription->person->email);
            $this->assertEquals($people[$person_ind]->email, $subscription->person->email);
            $this->assertNotNull($subscription->person->phone);
            $this->assertEquals($people[$person_ind]->phone, $subscription->person->phone);
            $this->assertGreaterThan(0, $subscription->id);
        }
    }

    public function testInvalidSubscription(): void
    {
        $data = $this->getData();
        $person = $data[0];

        $subscriptions = [
            [
                "person" => null,
                "qr" => "sdfasdf",
                "error" => "Person is required.",
            ],
            [
                "person" => new Person(),
                "qr" => "asdf",
                "error" => "Person is invalid.",
            ],
        ];

        // Iterate over the list of dictionaries
        foreach ($subscriptions as $p) {
            $subscription = new Subscription();
            $subscription->person = $p["person"];
            $subscription->qr = $p["qr"];

            $subscription_saved = false;
            try {
                $error = $subscription->save();
                $subscription_saved = true;
            } catch (Exception $e) {
                $this->assertEquals($p["error"], $e->getMessage());
            }
            $this->assertFalse($subscription_saved);
        }
    }

    public function testUploadCsv(): void
    {
        $csv = __DIR__ . '/data/google_forms.csv';
        Subscription::upload_csv($csv);
    }
}
