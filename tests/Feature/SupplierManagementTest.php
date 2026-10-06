<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Supplier;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    protected Admin $admin;
    protected Admin $purchasing;
    protected Admin $vp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'username' => 'admin_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Portal Admin',
            'role' => 'admin',
        ]);

        $this->purchasing = Admin::create([
            'username' => 'purchasing_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Rica Esaga',
            'role' => 'purchasing',
        ]);

        $this->vp = Admin::create([
            'username' => 'vp_' . uniqid(),
            'password' => bcrypt('secret'),
            'name' => 'Bernice Nikki Lee',
            'role' => 'vp',
        ]);
    }

    public function test_purchasing_can_create_supplier(): void
    {
        $response = $this->actingAs($this->purchasing)->post(route('stock-orders.suppliers.store'), [
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
            'address' => 'Brgy. Poblacion, Limay, Bataan',
            'attention' => 'Mr. Aaron Uy',
        ]);

        $response->assertRedirect(route('stock-orders.suppliers.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('suppliers', [
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
            'attention' => 'Mr. Aaron Uy',
            'is_active' => true,
        ]);
    }

    public function test_supplier_company_and_location_pair_must_be_unique(): void
    {
        Supplier::create([
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
        ]);

        $response = $this->actingAs($this->purchasing)->post(route('stock-orders.suppliers.store'), [
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
        ]);

        $response->assertSessionHasErrors(['location']);

        $this->assertDatabaseCount('suppliers', 1);
    }

    public function test_same_company_may_have_a_different_location(): void
    {
        Supplier::create([
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Limay Terminal',
        ]);

        $this->actingAs($this->purchasing)->post(route('stock-orders.suppliers.store'), [
            'company_name' => 'Agieko Fuel Trading',
            'location' => 'Batangas City',
        ])->assertSessionHas('success');

        $this->assertDatabaseCount('suppliers', 2);
    }

    public function test_purchasing_can_update_supplier(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
            'address' => 'Old address',
        ]);

        $response = $this->actingAs($this->purchasing)->post(route('stock-orders.suppliers.update', $supplier->id), [
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
            'address' => 'New address',
            'attention' => 'Ms. Dela Cruz',
        ]);

        $response->assertRedirect(route('stock-orders.suppliers.index'));

        $supplier->refresh();
        $this->assertEquals('New address', $supplier->address);
        $this->assertEquals('Ms. Dela Cruz', $supplier->attention);
    }

    public function test_deactivating_supplier_hides_it_from_active_dropdown(): void
    {
        $supplier = Supplier::create([
            'company_name' => 'Shell Tabangao',
            'location' => 'Tabangao',
        ]);

        $this->assertTrue($supplier->is_active);
        $this->assertEquals(1, Supplier::active()->count());

        // Toggle returns back() to wherever it was triggered from, so assert
        // on the outcome rather than a specific redirect target.
        $response = $this->actingAs($this->purchasing)
            ->post(route('stock-orders.suppliers.toggle-active', $supplier->id));

        $response->assertSessionHas('success');

        $supplier->refresh();
        $this->assertFalse($supplier->is_active);
        $this->assertEquals(0, Supplier::active()->count());

        // Still present for historical POs, just not offered in dropdowns.
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_vp_cannot_manage_suppliers(): void
    {
        $this->actingAs($this->vp)->get(route('stock-orders.suppliers.index'))->assertForbidden();

        $this->actingAs($this->vp)->post(route('stock-orders.suppliers.store'), [
            'company_name' => 'Unauthorized Co',
            'location' => 'Somewhere',
        ])->assertForbidden();

        $this->assertDatabaseMissing('suppliers', ['company_name' => 'Unauthorized Co']);
    }

    public function test_supplier_list_page_shows_suppliers(): void
    {
        Supplier::create([
            'company_name' => 'Petron Bataan Refinery',
            'location' => 'Limay',
            'attention' => 'Mr. Aaron Uy',
        ]);

        $response = $this->actingAs($this->purchasing)->get(route('stock-orders.suppliers.index'));

        $response->assertStatus(200);
        $response->assertSee('Petron Bataan Refinery');
        $response->assertSee('Mr. Aaron Uy');
    }
}
