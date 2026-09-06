import os, re

student_dir = r'c:\xampp\htdocs\cenlearn\system\student'
updated = []

pdm_theme_pattern = re.compile(r'\s*<div class="pdm-theme-item".*?</div>\s*</div>', re.DOTALL)
floating_bubble_pattern = re.compile(r'\s*<!-- Floating Dark Mode Bubble -->.*?<\/script>', re.DOTALL)
head_script_pattern = re.compile(r'\s*<script>\s*\(function\(\)\s*\{\s*const savedTheme = localStorage\.getItem\(\'cenlearn_theme\'\);.*?\}\)\(\);\s*<\/script>', re.DOTALL)

for f in os.listdir(student_dir):
    if f.endswith('.php'):
        path = os.path.join(student_dir, f)
        with open(path, 'r', encoding='utf-8') as fp:
            content = fp.read()
        
        orig = content
        
        # 1. Remove pdm-theme-item
        content = pdm_theme_pattern.sub('', content)
        
        # 2. Remove floating bubble & toggleDarkMode script block
        content = floating_bubble_pattern.sub('', content)
        
        # 3. Remove head script block
        content = head_script_pattern.sub('', content)

        # 4. Remove fallback script blocks referencing toggleDarkMode or pdmThemeCheck
        content = re.sub(r'<script>\s*function toggleDarkMode\(e\).*?<\/script>', '', content, flags=re.DOTALL)
        
        if content != orig:
            with open(path, 'w', encoding='utf-8') as fp:
                fp.write(content)
            updated.append(f)

print('Cleaned dark mode from files:', updated)
