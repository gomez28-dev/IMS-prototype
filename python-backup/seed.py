import os
import pandas as pd
from datetime import datetime
from app import create_app
from app.extensions import db
from app.models import Admin, Order, Delivery

def seed_database():
    app = create_app()
    with app.app_context():
        print("Initializing database tables...")
        db.create_all()

        # Seed default Admin
        admin = Admin.query.filter_by(username='admin').first()
        if not admin:
            print("Creating default admin user (admin / admin)...")
            admin = Admin(username='admin')
            admin.set_password('admin')
            db.session.add(admin)
            db.session.commit()
            print("Admin user created successfully.")
        else:
            print("Admin user already exists.")

        # Seed Excel data
        excel_file = 'DPET SALES INVENTORY.xlsx'
        if os.path.exists(excel_file):
            print(f"Reading data from {excel_file}...")
            df = pd.read_excel(excel_file, header=1)

            current_order = None

            for idx, row in df.iterrows():
                account_val = row.get('ACCOUNT')
                
                # Check if ACCOUNT is present and non-empty
                if pd.notna(account_val) and str(account_val).strip() != '':
                    order_date = row.get('DATE')
                    if pd.notna(order_date):
                        try:
                            order_date = pd.to_datetime(order_date).to_pydatetime()
                        except Exception:
                            order_date = datetime.utcnow()
                    else:
                        order_date = datetime.utcnow()

                    qty_ordered = row.get('QTY ORDERED')
                    qty_ordered = int(qty_ordered) if pd.notna(qty_ordered) else 0
                    
                    so_val = row.get('SO#')
                    if pd.notna(so_val):
                        so_number = str(int(so_val)) if isinstance(so_val, (int, float)) and so_val == int(so_val) else str(so_val).strip()
                    else:
                        so_number = f"SO-{idx+1}"

                    current_order = Order(
                        account=str(account_val).strip(),
                        date=order_date,
                        qty_ordered=qty_ordered,
                        so_number=so_number
                    )
                    db.session.add(current_order)
                    db.session.flush() # flush to get current_order.id
                    print(f"Created Order: {current_order.account} (SO# {current_order.so_number})")

                # Parse Delivery if DR# or QTY OUT is present and current_order exists
                dr_val = row.get('DR#')
                if current_order and pd.notna(dr_val):
                    deliv_date = row.get('DELIVERY DATE')
                    if pd.notna(deliv_date):
                        try:
                            deliv_date = pd.to_datetime(deliv_date).to_pydatetime()
                        except Exception:
                            deliv_date = datetime.utcnow()
                    else:
                        deliv_date = datetime.utcnow()

                    qty_out = row.get('QTY OUT')
                    qty_out = int(qty_out) if pd.notna(qty_out) else 0

                    status_raw = str(row.get('DELIVERY STATUS')).strip().upper() if pd.notna(row.get('DELIVERY STATUS')) else 'PENDING'
                    if status_raw == 'DONE':
                        status = 'FULFILLED'
                    elif status_raw in ['PENDING', 'FULFILLED', 'CANCELLED']:
                        status = status_raw
                    else:
                        status = 'PENDING'

                    remarks_val = str(row.get('REMARKS')).strip() if pd.notna(row.get('REMARKS')) else None

                    delivery = Delivery(
                        order_id=current_order.id,
                        dr_number=str(int(dr_val)) if isinstance(dr_val, (int, float)) and dr_val == int(dr_val) else str(dr_val).strip(),
                        delivery_date=deliv_date,
                        qty_out=qty_out,
                        status=status,
                        remarks=remarks_val
                    )
                    db.session.add(delivery)
                    print(f"  -> Added Delivery DR# {delivery.dr_number} (Qty Out: {delivery.qty_out}, Status: {delivery.status})")

            db.session.commit()
            print("Database seeding completed successfully!")
        else:
            print(f"Warning: {excel_file} not found. Skipping Excel import.")

if __name__ == '__main__':
    seed_database()
