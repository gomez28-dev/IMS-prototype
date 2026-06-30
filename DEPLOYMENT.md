# Deployment Guide: PythonAnywhere

This guide provides step-by-step instructions for deploying the **Admin Inventory Management System (IMS)** prototype on PythonAnywhere.

---

## 1. Console Setup (PythonAnywhere Bash Console)

Paste the following block of commands directly into your PythonAnywhere Bash console to clone the repository, create a virtual environment, install requirements, and seed the database:

```bash
# Clone the repository
git clone https://github.com/gomez28-dev/ims-prototype.git

# Create a virtual environment using Python 3.10
mkvirtualenv --python=/usr/bin/python3.10 ims-venv

# Change directory and install dependencies
cd ims-prototype && pip install -r requirements.txt

# Initialize database schema and seed legacy Excel data
python seed.py
```

---

## 2. WSGI Configuration

In the PythonAnywhere **Web** tab, click on the **WSGI configuration file** link (usually under the *Code* section: `/var/www/<YOUR_USERNAME>_pythonanywhere_com_wsgi.py`).

Replace the contents of that file with the following clean configuration. Be sure to replace `<YOUR_USERNAME>` with your actual PythonAnywhere username:

```python
import sys
import os

# Define project path
path = '/home/<YOUR_USERNAME>/ims-prototype'
if path not in sys.path:
    sys.path.append(path)

# Import Flask application factory and create the application instance
from app import create_app
application = create_app()
```

---

## 3. Web Tab Settings

After completing the console setup and WSGI configuration:

1. **Virtualenv Path**:
   Under the **Virtualenv** section on the **Web** tab, set the path to your created virtual environment:
   ```
   /home/<YOUR_USERNAME>/.virtualenvs/ims-venv
   ```
2. **Reload Web App**:
   Click the **Reload** button at the top of the **Web** tab. Your site will now be live at `https://<YOUR_USERNAME>.pythonanywhere.com`.
