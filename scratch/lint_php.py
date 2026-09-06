import os
import subprocess

root_dir = r'c:\xampp\htdocs\cenlearn\system'
php_bin = r'C:\xampp\php\php.exe'
errors = []

for root, dirs, files in os.walk(root_dir):
    for f in files:
        if f.endswith('.php'):
            filepath = os.path.join(root, f)
            res = subprocess.run([php_bin, '-l', filepath], capture_output=True, text=True)
            if 'No syntax errors detected' not in res.stdout:
                errors.append((filepath, res.stdout or res.stderr))

if errors:
    print(f"FOUND {len(errors)} SYNTAX ERRORS:")
    for err in errors:
        print(err[0], err[1])
else:
    print("ALL PHP FILES PASSED SYNTAX LINTING PERFECTLY!")
