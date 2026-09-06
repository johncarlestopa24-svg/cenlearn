import os

student_dir = r'c:\xampp\htdocs\cenlearn\system\student'
updated = []

for f in os.listdir(student_dir):
    if f.endswith('.php'):
        path = os.path.join(student_dir, f)
        with open(path, 'r', encoding='utf-8') as fp:
            content = fp.read()
        
        modified = False
        
        # 1. Add item to profileMenu
        if '<div class="profile-dropdown-menu" id="profileMenu">' in content and 'openStudentProfileModal()' not in content:
            idx = content.find('<div class="profile-dropdown-menu" id="profileMenu">')
            end_hdr = content.find('</div>', idx)
            if end_hdr != -1:
                insert_pos = end_hdr + 6
                new_item = '\n          <a href="javascript:void(0)" class="pdm-item" onclick="openStudentProfileModal()"><i class="fa fa-user-circle"></i> Student Profile</a>'
                content = content[:insert_pos] + new_item + content[insert_pos:]
                modified = True
        
        # 2. Add include before </body>
        if 'student_profile_modal.php' not in content and '</body>' in content:
            content = content.replace('</body>', "<?php include '../includes/student_profile_modal.php'; ?>\n</body>")
            modified = True
            
        if modified:
            with open(path, 'w', encoding='utf-8') as fp:
                fp.write(content)
            updated.append(f)

print('Updated files:', updated)
