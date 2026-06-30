from flask_wtf import FlaskForm
from wtforms import StringField, PasswordField, SubmitField, IntegerField, DateField, SelectField, TextAreaField
from wtforms.validators import DataRequired, InputRequired, Optional

class LoginForm(FlaskForm):
    username = StringField('Username', validators=[DataRequired()])
    password = PasswordField('Password', validators=[DataRequired()])
    login_button = SubmitField('Login')

class OrderForm(FlaskForm):
    account = StringField('Account', validators=[DataRequired()])
    date = DateField('Order Date', validators=[DataRequired()], format='%Y-%m-%d')
    qty_ordered = IntegerField('Qty Ordered', validators=[InputRequired()])
    so_number = StringField('SO Number', validators=[DataRequired()])
    save_button = SubmitField('Save Order')

class DeliveryForm(FlaskForm):
    dr_number = StringField('DR Number', validators=[DataRequired()])
    delivery_date = DateField('Delivery Date', validators=[DataRequired()], format='%Y-%m-%d')
    qty_out = IntegerField('Qty Out', validators=[InputRequired()])
    status = SelectField('Status', choices=[
        ('PENDING', 'PENDING'),
        ('FULFILLED', 'FULFILLED'),
        ('CANCELLED', 'CANCELLED')
    ], validators=[DataRequired()])
    remarks = TextAreaField('Remarks', validators=[Optional()])
    save_button = SubmitField('Save Delivery')
