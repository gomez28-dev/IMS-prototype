from datetime import datetime
from werkzeug.security import generate_password_hash, check_password_hash
from flask_login import UserMixin
from app.extensions import db

class Admin(UserMixin, db.Model):
    __tablename__ = 'admins'
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(64), unique=True, nullable=False)
    password_hash = db.Column(db.String(256), nullable=False)

    def set_password(self, password):
        self.password_hash = generate_password_hash(password)

    def check_password(self, password):
        return check_password_hash(self.password_hash, password)

class Order(db.Model):
    __tablename__ = 'orders'
    id = db.Column(db.Integer, primary_key=True)
    account = db.Column(db.String(128), nullable=False)
    date = db.Column(db.DateTime, nullable=False, default=datetime.utcnow)
    qty_ordered = db.Column(db.Integer, nullable=False, default=0)
    so_number = db.Column(db.String(64), nullable=False)

    deliveries = db.relationship('Delivery', backref='order', lazy=True, cascade='all, delete-orphan')

    @property
    def total_qty_out(self):
        return sum(d.qty_out for d in self.deliveries if d.status != 'CANCELLED')

    @property
    def remaining_balance(self):
        return self.qty_ordered - self.total_qty_out

class Delivery(db.Model):
    __tablename__ = 'deliveries'
    id = db.Column(db.Integer, primary_key=True)
    order_id = db.Column(db.Integer, db.ForeignKey('orders.id'), nullable=False)
    dr_number = db.Column(db.String(64), nullable=False)
    delivery_date = db.Column(db.DateTime, nullable=False, default=datetime.utcnow)
    qty_out = db.Column(db.Integer, nullable=False, default=0)
    status = db.Column(db.String(20), nullable=False, default='PENDING')
    remarks = db.Column(db.Text, nullable=True)
