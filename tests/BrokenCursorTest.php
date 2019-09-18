<?php

namespace Tests;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use PHPUnit\Framework\TestCase;

class BrokenCursorTest extends TestCase
{
    public function setUp(): void
    {
        $db = new DB;
        $db->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $schema = $this->schema();

        $schema->create('users', static function(Blueprint $table) {
            $table->increments('id');
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('things', static function(Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        $schema->create('user_things', static function(Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('userId');
            $table->unsignedInteger('thingId');

            $table->foreign('userId')->references('id')->on('users');
            $table->foreign('thingId')->references('id')->on('things');

            $table->unique(['userId', 'thingId']);
        });
    }

    /**
     * Tear down the database schema.
     *
     * @return void
     */
    public function tearDown(): void
    {
        $schema = $this->schema();

        $schema->drop('users');
        $schema->drop('things');
    }

    public function testBrokenBehaviour()
    {
        // Insert data with IDs that we know up front, to simulate a database that's been in use for some time.
        $userId = 1000;
        $unrelatedThingId = 2000;
        $relatedThingId = 3000;
        $secondRelatedThingId = 4000;
        $mismatchingId = 5000;

        // We'll use a pattern to define titles for ensured consistency.
        $expectedThingTitle = static function($id) {
            return "Thing {$id}";
        };

        DB::table('users')->insert([
            'id' => $userId,
        ]);

        DB::table('things')->insert([
            'id'    => $unrelatedThingId,
            'title' => $expectedThingTitle($unrelatedThingId),
        ]);
        DB::table('things')->insert([
            'id'    => $relatedThingId,
            'title' => $expectedThingTitle($relatedThingId),
        ]);
        DB::table('things')->insert([
            'id'    => $secondRelatedThingId,
            'title' => $expectedThingTitle($secondRelatedThingId),
        ]);

        // This is where it goes wrong. When IDs overlap, it'll result in the wrong ID being in the return object.
        // Any updates to this object will update the _wrong_ record in the database.
        DB::table('user_things')->insert([
            'id'      => $unrelatedThingId,
            'userId'  => $userId,
            'thingId' => $relatedThingId,
        ]);

        // This will also throw an error, due to a model not existing with this ID.
        DB::table('user_things')->insert([
            'id'      => $mismatchingId,
            'userId'  => $userId,
            'thingId' => $secondRelatedThingId,
        ]);

        // Let's keep the correct models in memory for easy testing.
        /** @var Models\User $user */
        $user = Models\User::findOrFail($userId);

        /** @var Models\Thing $unrelatedThing */
        $unrelatedThing = Models\Thing::findOrFail($unrelatedThingId);

        /** @var Models\Thing $relatedThing */
        $relatedThing = Models\Thing::findOrFail($relatedThingId);

        /** @var Models\Thing $secondRelatedThing */
        $secondRelatedThing = Models\Thing::findOrFail($secondRelatedThingId);

        // We'll check against these expected values multiple times.
        // They're stored now, because as you'll see, they are not guaranteed to be correct anymore in a bit..
        $expectedThingIds = [$relatedThing->id, $secondRelatedThing->id];
        $expectedThingTitles = [$relatedThing->title, $secondRelatedThing->title];

        // First, let's test looping through a loaded collection.
        // This all works fine.
        foreach ($user->things as $thing) {
            $this->assertContains($thing->id, $expectedThingIds);
            $this->assertContains($thing->title, $expectedThingTitles);

            $thing->title = $expectedThingTitle($thing->id);

            $this->assertFalse($thing->isDirty());
        }

        // Explicitly calling get() actually works fine too.
        foreach ($user->things()->get() as $thing) {
            $this->assertContains($thing->id, [$relatedThingId, $secondRelatedThingId]);
            $this->assertContains($thing->title, $expectedThingTitles);

            $thing->title = $expectedThingTitle($thing->id);

            $this->assertFalse($thing->isDirty());
        }

        // This is where things go haywire.
        foreach ($user->things()->cursor() as $thing) {
            // We're getting the wrong IDs
            $this->assertNotContains($thing->id, $expectedThingIds);

            // Titles will match what we expected because they are not in the pivot table...
            $this->assertContains($thing->title, $expectedThingTitles);

            // But if we try to regenerate it, it won't match what we expect.
            $this->assertNotEquals($expectedThingTitle($thing->id), $thing->title);

            // And if we change it...
            $thing->title = $expectedThingTitle($thing->id);

            // The model actually becomes dirty.
            $this->assertTrue($thing->isDirty());
        }

        // Last, we'll demonstrate how destructive and dangerous this behaviour is.
        // This is also why we determined our IDs up front, so that we can test both cases of this going wrong.
        foreach ($user->things()->cursor() as $thing) {
            // If the "ID" we have now is that of our unrelated thing,
            // it'll mistakenly update this model instead of the one we're actually trying to update.
            if ($thing->id === $unrelatedThingId) {
                // Just to be painfully clear that we have the correct data BEFORE our mutation, we'll refresh and check.
                $relatedThing->refresh();
                $unrelatedThing->refresh();

                $this->assertEquals($relatedThingId, $relatedThing->id);
                $this->assertEquals($expectedThingTitle($relatedThingId), $relatedThing->title);

                $this->assertEquals($unrelatedThingId, $unrelatedThing->id);
                $this->assertEquals($expectedThingTitle($unrelatedThingId), $unrelatedThing->title);
            } elseif ($thing->id === $mismatchingId) {
                // Assert as before.
                $secondRelatedThing->refresh();

                $this->assertEquals($secondRelatedThingId, $secondRelatedThing->id);
                $this->assertEquals($expectedThingTitle($secondRelatedThingId), $secondRelatedThing->title);
            }

            // Now, modify the object we got from the cursor.
            $thing->title = 'Mutated';

            $this->assertTrue($thing->isDirty());

            // This will always work just fine, even if the model in question doesn't _actually_ exist..
            $thing->saveOrFail();

            if ($thing->id === $unrelatedThingId) {
                // And repeat our checks, but this time we're getting the wrong things back.
                $relatedThing->refresh();
                $unrelatedThing->refresh();

                // The actual related thing hasn't updated.
                $this->assertEquals($relatedThingId, $relatedThing->id);
                $this->assertEquals($expectedThingTitle($relatedThingId), $relatedThing->title);

                // But the completely unrelated thing has.
                $this->assertEquals($unrelatedThingId, $unrelatedThing->id);
                $this->assertEquals('Mutated', $unrelatedThing->title);
            } elseif ($thing->id === $mismatchingId) {
                // Attempting to refresh this will fail.
                $exceptionThrown = false;
                try {
                    $thing->refresh();
                } catch (ModelNotFoundException $e) {
                    $exceptionThrown = true;
                }

                $this->assertTrue($exceptionThrown);
            }
        }
    }

    /**
     * Get a schema builder instance.
     *
     * @return Builder
     */
    protected function schema(): Builder
    {
        return Eloquent::getConnectionResolver()->connection()->getSchemaBuilder();
    }
}
