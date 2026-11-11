<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The layout polls /realtime/state to notice when another user changed a
 * status, then patches the badges it already shows and quietly re-renders the
 * page body. That only works if the endpoint's checksum really does move on
 * every operational change, so these tests pin that down.
 */
class RealtimeStatePollingTest extends TestCase
{
    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'rt_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Realtime Tester',
            'role' => 'admin',
        ]);
    }

    private function order(string $clearingStatus = 'Pending'): Order
    {
        return Order::create([
            'account' => 'Realtime Client',
            'location' => 'San Simon',
            'so_number' => 'SO-RT-' . uniqid(),
            'date' => now(),
            'qty_ordered' => 1000,
            'price' => 50,
            'clearing_status' => $clearingStatus,
            'status' => 'Pending',
        ]);
    }

    public function test_guests_cannot_poll_the_state_endpoint(): void
    {
        $this->get(route('realtime.state'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_receive_a_checksum_and_an_order_map(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->admin)->getJson(route('realtime.state'));

        $response->assertOk();
        $response->assertJsonStructure(['checksum', 'timestamp', 'orders']);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $response->assertJsonPath('orders.' . $order->id . '.clearing_status', 'Pending');
        $response->assertJsonPath('orders.' . $order->id . '.id', $order->id);
    }

    /**
     * The endpoint is only useful if every authenticated page actually carries
     * the container to swap and the script that polls it.
     */
    public function test_pages_carry_the_sync_container_and_script(): void
    {
        $html = $this->actingAs($this->admin)->get(route('dashboard'))->getContent();

        $this->assertStringContainsString('data-realtime-container="main"', $html);
        $this->assertStringContainsString('id="ims-realtime-sync"', $html);
    }

    public function test_approving_an_order_moves_the_checksum_and_the_order_map(): void
    {
        $order = $this->order('Pending');

        $before = $this->actingAs($this->admin)->getJson(route('realtime.state'))->json();

        $order->update(['clearing_status' => 'Approved']);

        $after = $this->actingAs($this->admin)->getJson(route('realtime.state'))->json();

        $this->assertNotSame(
            $before['checksum'],
            $after['checksum'],
            'Approving an order has to change the checksum, or no other screen ever refreshes.'
        );
        $this->assertSame('Approved', $after['orders'][$order->id]['clearing_status']);
    }

    /**
     * A status changed by a raw query - no Eloquent save, so no touched
     * updated_at - must still move the checksum. That is why each table's
     * signature reads the status columns themselves and not just the
     * timestamps.
     */
    public function test_a_delivery_status_changed_outside_eloquent_still_moves_the_checksum(): void
    {
        $order = $this->order();

        $deliveryId = DB::table('deliveries')->insertGetId([
            'order_id' => $order->id,
            'dr_number' => 'DR-RT-' . uniqid(),
            'delivery_date' => now()->toDateString(),
            'qty_out' => 500,
            'status' => 'PENDING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = $this->actingAs($this->admin)->getJson(route('realtime.state'))->json();

        DB::table('deliveries')->where('id', $deliveryId)->update(['status' => 'FULFILLED']);

        $after = $this->actingAs($this->admin)->getJson(route('realtime.state'))->json();

        $this->assertNotSame($before['checksum'], $after['checksum']);
    }
}
