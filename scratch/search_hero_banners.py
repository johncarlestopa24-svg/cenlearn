import os, sys
sys.stdout.reconfigure(encoding='utf-8')

student_dir = r'c:\xampp\htdocs\cenlearn\system\student'

for root, dirs, files in os.walk(student_dir):
    for f in files:
        if f.endswith('.php'):
            p = os.path.join(root, f)
            with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
                lines = fh.readlines()
            for idx, line in enumerate(lines, 1):
                if 'hero-banner' in line.lower() or 'hero' in line.lower() or 'tracker' in line.lower():
                    print(f"{f}:L{idx}: {line.strip()[:110]}")
