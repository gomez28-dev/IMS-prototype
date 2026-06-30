from flask import Flask
from app.config import Config
from app.extensions import db, login_manager
from app.models import Admin

def create_app(config_class=Config):
    app = Flask(__name__)
    app.config.from_object(config_class)

    db.init_app(app)
    login_manager.init_app(app)

    @login_manager.user_loader
    def load_user(user_id):
        return db.session.get(Admin, int(user_id))

    from app.routes import bp as main_bp
    app.register_blueprint(main_bp)

    return app
