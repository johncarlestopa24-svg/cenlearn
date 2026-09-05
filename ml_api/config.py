import os

# Database connection config — update these to match your XAMPP MySQL setup
# On Vercel, these will be loaded from Environment Variables.
DB_CONFIG = {
    "host":     os.environ.get("DB_HOST", "localhost"),
    "port":     int(os.environ.get("DB_PORT", 3306)),
    "user":     os.environ.get("DB_USER", "root"),
    "password": os.environ.get("DB_PASSWORD", ""),           # default XAMPP has no password
    "database": os.environ.get("DB_NAME", "cenlearn_db"),
}

# Flask API settings
API_HOST = os.environ.get("API_HOST", "127.0.0.1")
API_PORT = int(os.environ.get("API_PORT", 5001))

