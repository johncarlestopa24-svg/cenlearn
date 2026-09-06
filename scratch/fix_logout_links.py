import os

root_dir = r'c:\xampp\htdocs\cenlearn'
count = 0

for root, dirs, files in os.walk(root_dir):
    for f in files:
        if f.endswith('.php'):
            filepath = os.path.join(root, f)
            with open(filepath, 'r', encoding='utf-8', errors='ignore') as fp:
                content = fp.read()

            new_content = content
            new_content = new_content.replace('href="../logout"', 'href="/cenlearn/logout"')
            new_content = new_content.replace("href='../logout'", "href='/cenlearn/logout'")
            new_content = new_content.replace('href="logout"', 'href="/cenlearn/logout"')
            new_content = new_content.replace("href='logout'", "href='/cenlearn/logout'")

            if new_content != content:
                with open(filepath, 'w', encoding='utf-8') as fp:
                    fp.write(new_content)
                count += 1
                print(f"Updated logout links in: {os.path.relpath(filepath, root_dir)}")

print(f"Total files updated: {count}")
