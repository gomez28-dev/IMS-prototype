<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ImportSqliteData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:sqlite-data';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import admins, orders, and deliveries from the historical SQLite database to MySQL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting SQLite to MySQL data migration...');

        // Check if database file exists
        $dbPath = base_path('instance/inventory.db');
        if (!file_exists($dbPath)) {
            $this->error("Error: SQLite database file not found at: {$dbPath}");
            $this->info("Please upload your 'inventory.db' inside the 'instance' folder first.");
            return 1;
        }

        try {
            // Read SQLite tables
            $sqliteAdmins = DB::connection('sqlite_import')->table('admins')->get();
            $sqliteOrders = DB::connection('sqlite_import')->table('orders')->get();
            $sqliteDeliveries = DB::connection('sqlite_import')->table('deliveries')->get();
        } catch (\Exception $e) {
            $this->error("Failed to connect or read from SQLite database: " . $e->getMessage());
            return 1;
        }

        $this->info("Found in SQLite database:");
        $this->info("- Admins: " . $sqliteAdmins->count());
        $this->info("- Orders: " . $sqliteOrders->count());
        $this->info("- Deliveries: " . $sqliteDeliveries->count());

        if (!$this->confirm('This will truncate your current MySQL admins, orders, and deliveries tables before importing. Do you want to proceed?', true)) {
            $this->info('Migration cancelled.');
            return 0;
        }

        DB::transaction(function () use ($sqliteAdmins, $sqliteOrders, $sqliteDeliveries) {
            // Disable foreign key checks
            Schema::disableForeignKeyConstraints();

            // Truncate tables
            DB::table('deliveries')->truncate();
            DB::table('orders')->truncate();
            DB::table('admins')->truncate();

            // Import Admins
            $this->info('Importing Admins...');
            foreach ($sqliteAdmins as $admin) {
                DB::table('admins')->insert([
                    'id' => $admin->id,
                    'username' => $admin->username,
                    'password' => $admin->password_hash, // Map password_hash to password
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Import Orders
            $this->info('Importing Orders...');
            foreach ($sqliteOrders as $order) {
                DB::table('orders')->insert([
                    'id' => $order->id,
                    'account' => $order->account,
                    'date' => $order->date,
                    'qty_ordered' => $order->qty_ordered,
                    'so_number' => $order->so_number,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Import Deliveries
            $this->info('Importing Deliveries...');
            foreach ($sqliteDeliveries as $delivery) {
                DB::table('deliveries')->insert([
                    'id' => $delivery->id,
                    'order_id' => $delivery->order_id,
                    'dr_number' => $delivery->dr_number,
                    'delivery_date' => $delivery->delivery_date,
                    'qty_out' => $delivery->qty_out,
                    'status' => $delivery->status,
                    'remarks' => $delivery->remarks,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Re-enable foreign key checks
            Schema::enableForeignKeyConstraints();
        });

        $this->info('Data migration completed successfully!');
        return 0;
    }
}
