import os, sys
sys.stdout.reconfigure(encoding='utf-8')

student_dir = r'c:\xampp\htdocs\cenlearn\system\student'

for root, dirs, files in os.walk(student_dir):
    for f in files:
        if f.endswith('.php'):
            p = os.path.join(root, f)
            with open(p, 'r', encoding='utf-8', errors='ignore') as fh:
                content = fh.read()
            has_sublive = 'subLiveClass' in content
            has_liveclass = 'live_class' in content
            has_onlineclass = 'Online Class' in content
            print(f"{f}: subLive={has_sublive}, live_class={has_liveclass}, OnlineClass={has_onlineclass}")
