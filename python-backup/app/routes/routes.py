from flask import Blueprint, render_template, redirect, url_for, flash, request, abort, send_file
from flask_login import login_user, logout_user, login_required, current_user
from app.extensions import db
from app.models import Admin, Order, Delivery
from app.forms import LoginForm, OrderForm, DeliveryForm
from datetime import datetime
import io
import pandas as pd

bp = Blueprint('main', __name__)

@bp.route('/login', methods=['GET', 'POST'])
def login():
    if current_user.is_authenticated:
        return redirect(url_for('main.dashboard'))
    
    form = LoginForm()
    if form.validate_on_submit():
        admin = db.session.scalar(db.select(Admin).filter_by(username=form.username.data))
        if admin and admin.check_password(form.password.data):
            login_user(admin)
            flash('Logged in successfully.', 'success')
            next_page = request.args.get('next')
            return redirect(next_page or url_for('main.dashboard'))
        else:
            flash('Invalid username or password.', 'danger')
    
    return render_template('login.html', form=form)

@bp.route('/logout')
@login_required
def logout():
    logout_user()
    flash('Logged out successfully.', 'info')
    return redirect(url_for('main.login'))

@bp.route('/')
@login_required
def dashboard():
    search_query = request.args.get('search', '').strip()
    stmt = db.select(Order)
    if search_query:
        stmt = stmt.filter(
            db.or_(
                Order.account.ilike(f"%{search_query}%"),
                Order.so_number.ilike(f"%{search_query}%")
            )
        )
    orders = db.session.scalars(stmt.order_by(Order.date.desc())).all()
    return render_template('index.html', orders=orders, search_query=search_query)

@bp.route('/order/new', methods=['GET', 'POST'])
@login_required
def new_order():
    form = OrderForm()
    if form.validate_on_submit():
        order = Order(
            account=form.account.data,
            date=datetime.combine(form.date.data, datetime.min.time()),
            qty_ordered=form.qty_ordered.data,
            so_number=form.so_number.data
        )
        db.session.add(order)
        db.session.commit()
        flash('Order created successfully.', 'success')
        return redirect(url_for('main.dashboard'))
    return render_template('order_form.html', form=form, title='New Order')

@bp.route('/order/<int:id>/edit', methods=['GET', 'POST'])
@login_required
def edit_order(id):
    order = db.session.get(Order, id)
    if order is None:
        abort(404)
    form = OrderForm(obj=order)
    if request.method == 'GET':
        form.date.data = order.date.date() if isinstance(order.date, datetime) else order.date
    if form.validate_on_submit():
        order.account = form.account.data
        order.date = datetime.combine(form.date.data, datetime.min.time())
        order.qty_ordered = form.qty_ordered.data
        order.so_number = form.so_number.data
        db.session.commit()
        flash('Order updated successfully.', 'success')
        return redirect(url_for('main.dashboard'))
    return render_template('order_form.html', form=form, title='Edit Order', order=order)

@bp.route('/order/<int:id>/deliveries')
@login_required
def order_deliveries(id):
    order = db.session.get(Order, id)
    if order is None:
        abort(404)
    deliveries = db.session.scalars(
        db.select(Delivery).filter_by(order_id=order.id).order_by(Delivery.delivery_date.asc())
    ).all()
    return render_template('deliveries.html', order=order, deliveries=deliveries)

@bp.route('/order/<int:order_id>/delivery/new', methods=['GET', 'POST'])
@login_required
def new_delivery(order_id):
    order = db.session.get(Order, order_id)
    if order is None:
        abort(404)
    form = DeliveryForm()
    if form.validate_on_submit():
        if form.status.data != 'CANCELLED':
            if form.qty_out.data > order.remaining_balance:
                flash("Error: Delivery quantity exceeds the remaining order balance!", "danger")
                return render_template('delivery_form.html', form=form, title='New Delivery', order=order)
        
        delivery = Delivery(
            order_id=order.id,
            dr_number=form.dr_number.data,
            delivery_date=datetime.combine(form.delivery_date.data, datetime.min.time()),
            qty_out=form.qty_out.data,
            status=form.status.data,
            remarks=form.remarks.data
        )
        db.session.add(delivery)
        db.session.commit()
        flash('Delivery added successfully.', 'success')
        return redirect(url_for('main.order_deliveries', id=order.id))
    return render_template('delivery_form.html', form=form, title='New Delivery', order=order)

@bp.route('/delivery/<int:id>/edit', methods=['GET', 'POST'])
@login_required
def edit_delivery(id):
    delivery = db.session.get(Delivery, id)
    if delivery is None:
        abort(404)
    order = delivery.order
    form = DeliveryForm(obj=delivery)
    if request.method == 'GET':
        form.delivery_date.data = delivery.delivery_date.date() if isinstance(delivery.delivery_date, datetime) else delivery.delivery_date
    if form.validate_on_submit():
        if form.status.data != 'CANCELLED':
            adjusted_balance = order.remaining_balance
            if delivery.status != 'CANCELLED':
                adjusted_balance += delivery.qty_out
            if form.qty_out.data > adjusted_balance:
                flash("Error: Delivery quantity exceeds the remaining order balance!", "danger")
                return render_template('delivery_form.html', form=form, title='Edit Delivery', order=order, delivery=delivery)
        
        delivery.dr_number = form.dr_number.data
        delivery.delivery_date = datetime.combine(form.delivery_date.data, datetime.min.time())
        delivery.qty_out = form.qty_out.data
        delivery.status = form.status.data
        delivery.remarks = form.remarks.data
        db.session.commit()
        flash('Delivery updated successfully.', 'success')
        return redirect(url_for('main.order_deliveries', id=order.id))
    return render_template('delivery_form.html', form=form, title='Edit Delivery', order=order, delivery=delivery)

@bp.route('/order/<int:id>/delete', methods=['POST'])
@login_required
def delete_order(id):
    order = db.session.get(Order, id)
    if order is None:
        abort(404)
    db.session.delete(order)
    db.session.commit()
    flash('Order deleted successfully.', 'success')
    return redirect(url_for('main.dashboard'))

@bp.route('/delivery/<int:id>/delete', methods=['POST'])
@login_required
def delete_delivery(id):
    delivery = db.session.get(Delivery, id)
    if delivery is None:
        abort(404)
    order_id = delivery.order_id
    db.session.delete(delivery)
    db.session.commit()
    flash('Delivery deleted successfully.', 'success')
    return redirect(url_for('main.order_deliveries', id=order_id))

@bp.route('/export/excel')
@login_required
def export_excel():
    orders = db.session.scalars(db.select(Order).order_by(Order.date.desc())).all()
    rows = []
    
    for order in orders:
        deliveries = db.session.scalars(
            db.select(Delivery).filter_by(order_id=order.id).order_by(Delivery.delivery_date.asc())
        ).all()
        
        running_balance = order.qty_ordered
        
        if not deliveries:
            rows.append({
                'ACCOUNT': order.account,
                'DATE': order.date.strftime('%Y-%m-%d') if order.date else '',
                'QTY ORDERED': order.qty_ordered,
                'SO#': order.so_number,
                'DR#': '',
                'DELIVERY DATE': '',
                'QTY OUT': '',
                'DELIVERY BALANCE': running_balance,
                'DELIVERY STATUS': '',
                'REMARKS': ''
            })
        else:
            for idx, delivery in enumerate(deliveries):
                if delivery.status != 'CANCELLED':
                    running_balance -= delivery.qty_out
                
                status_mapped = 'DONE' if delivery.status == 'FULFILLED' else delivery.status
                
                if idx == 0:
                    rows.append({
                        'ACCOUNT': order.account,
                        'DATE': order.date.strftime('%Y-%m-%d') if order.date else '',
                        'QTY ORDERED': order.qty_ordered,
                        'SO#': order.so_number,
                        'DR#': delivery.dr_number,
                        'DELIVERY DATE': delivery.delivery_date.strftime('%Y-%m-%d') if delivery.delivery_date else '',
                        'QTY OUT': delivery.qty_out,
                        'DELIVERY BALANCE': running_balance,
                        'DELIVERY STATUS': status_mapped,
                        'REMARKS': delivery.remarks or ''
                    })
                else:
                    rows.append({
                        'ACCOUNT': '',
                        'DATE': '',
                        'QTY ORDERED': '',
                        'SO#': '',
                        'DR#': delivery.dr_number,
                        'DELIVERY DATE': delivery.delivery_date.strftime('%Y-%m-%d') if delivery.delivery_date else '',
                        'QTY OUT': delivery.qty_out,
                        'DELIVERY BALANCE': running_balance,
                        'DELIVERY STATUS': status_mapped,
                        'REMARKS': delivery.remarks or ''
                    })

    df = pd.DataFrame(rows)
    output = io.BytesIO()
    with pd.ExcelWriter(output, engine='openpyxl') as writer:
        df.to_excel(writer, index=False, sheet_name='Sheet1')
    output.seek(0)
    
    return send_file(
        output,
        mimetype='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        as_attachment=True,
        download_name='inventory_export.xlsx'
    )
