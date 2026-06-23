#!/usr/bin/env python3
import os
import sys
import json
import time
import base64
import urllib.request
import urllib.error
import threading
import queue
import tkinter as tk
from tkinter import ttk, messagebox, scrolledtext, filedialog

# Windows startup integration
try:
    import winreg
    WINDOWS_SYSTEM = True
except ImportError:
    WINDOWS_SYSTEM = False

CONFIG_FILE = ".sync_config.json"
status_queue = queue.Queue()
sync_stop_event = threading.Event()
sync_thread = None

def get_config_path():
    # Store config in Windows AppData (or user home) to persist across restarts/moves
    app_data = os.environ.get("APPDATA")
    if not app_data:
        app_data = os.path.expanduser("~")
    config_dir = os.path.join(app_data, "PKHVaultSync")
    try:
        os.makedirs(config_dir, exist_ok=True)
    except Exception:
        pass
    return os.path.join(config_dir, "sync_config.json")

def get_default_vault_dir():
    if getattr(sys, 'frozen', False):
        # Running as compiled exe
        return os.path.dirname(os.path.abspath(sys.executable))
    else:
        # Running as script
        return os.path.dirname(os.path.abspath(__file__))

def set_startup_registry(enabled=True):
    if not WINDOWS_SYSTEM:
        return False, "Not running on a Windows system."
    
    key_path = r"Software\Microsoft\Windows\CurrentVersion\Run"
    key_name = "VaultSyncAgent"
    
    if getattr(sys, 'frozen', False):
        # Running as compiled exe
        exe = sys.executable
        cmd = f'"{exe}" --minimized'
    else:
        # Running as script
        exe = sys.executable
        if exe.endswith("python.exe"):
            exe_w = exe.replace("python.exe", "pythonw.exe")
            if os.path.exists(exe_w):
                exe = exe_w
        script_path = os.path.abspath(__file__)
        cmd = f'"{exe}" "{script_path}" --minimized'
    
    try:
        key = winreg.OpenKey(winreg.HKEY_CURRENT_USER, key_path, 0, winreg.KEY_SET_VALUE)
        if enabled:
            winreg.SetValueEx(key, key_name, 0, winreg.REG_SZ, cmd)
            message = "Added registry auto-run key."
        else:
            try:
                winreg.DeleteValue(key, key_name)
                message = "Deleted registry auto-run key."
            except FileNotFoundError:
                message = "Auto-run key already removed."
        winreg.CloseKey(key)
        return True, message
    except Exception as e:
        return False, f"Failed to modify startup registry: {str(e)}"

def http_post(url, data_dict, timeout=30):
    try:
        data_bytes = json.dumps(data_dict).encode('utf-8')
        req = urllib.request.Request(
            url,
            data=data_bytes,
            headers={
                'Content-Type': 'application/json',
                'User-Agent': 'PKH-Sync-Agent/1.0',
                'X-Requested-With': 'XMLHttpRequest'
            }
        )
        with urllib.request.urlopen(req, timeout=timeout) as response:
            status = response.status
            body = response.read().decode('utf-8')
            return status, body
    except urllib.error.HTTPError as e:
        try:
            err_body = e.read().decode('utf-8')
        except Exception:
            err_body = str(e)
        return e.code, err_body
    except Exception as e:
        raise Exception(f"Connection failed: {str(e)}")

def collect_local_files(vault_dir):
    files_map = {}
    allowed_exts = {'md', 'markdown', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'pdf', 'zip'}
    current_exe = os.path.basename(sys.executable)
    
    for root, dirs, filenames in os.walk(vault_dir):
        # Exclude hidden folders (.obsidian, .git, etc.) and the Recycle Bin
        dirs[:] = [d for d in dirs if not d.startswith('.') and d != "Recycle Bin"]
        
        for filename in filenames:
            # Skip hidden files, the script itself, config files, and the running exe
            if filename.startswith('.') or filename == "sync_agent.py" or filename == CONFIG_FILE or filename == "sync_config.json" or filename == current_exe:
                continue
                
            abs_path = os.path.join(root, filename)
            rel_path = os.path.relpath(abs_path, vault_dir).replace('\\', '/')
            
            ext = os.path.splitext(filename)[1].lower().lstrip('.')
            if ext in allowed_exts:
                try:
                    mtime = int(os.path.getmtime(abs_path))
                    files_map[rel_path] = mtime
                except Exception:
                    pass
    return files_map

def get_versioned_paths(local_dir, rel_path):
    dir_name, file_name = os.path.split(rel_path)
    base_name, ext = os.path.splitext(file_name)
    
    import re
    match = re.search(r'(.*?)(?:V\d+)(?:local|web)$', base_name)
    if match:
        prefix = match.group(1)
    else:
        prefix = base_name
        
    version = 0
    while True:
        version += 1
        local_name = f"{prefix}V{version}local{ext}"
        web_name = f"{prefix}V{version}web{ext}"
        
        local_rel = os.path.join(dir_name, local_name).replace('\\', '/')
        web_rel = os.path.join(dir_name, web_name).replace('\\', '/')
        
        local_abs = os.path.join(local_dir, local_rel.replace('/', os.sep))
        web_abs = os.path.join(local_dir, web_rel.replace('/', os.sep))
        
        if not os.path.exists(local_abs) and not os.path.exists(web_abs):
            return local_rel, web_rel

def prune_empty_dirs(vault_dir):
    for root, dirs, files in os.walk(vault_dir, topdown=False):
        if os.path.basename(root).startswith('.') or os.path.basename(root) == "Recycle Bin":
            continue
        for d in dirs:
            dir_path = os.path.join(root, d)
            if not os.path.basename(dir_path).startswith('.') and os.path.basename(dir_path) != "Recycle Bin" and os.path.exists(dir_path):
                try:
                    if not os.listdir(dir_path):
                        os.rmdir(dir_path)
                except Exception:
                    pass

def save_config_file(config):
    config_path = get_config_path()
    try:
        with open(config_path, 'w') as f:
            json.dump(config, f, indent=4)
    except Exception:
        pass

def perform_sync(config):
    url = config["url"].rstrip('/')
    vault = config["vault"]
    username = config["username"]
    password = config["password"]
    synced_files = config.get("synced_files", {})
    
    sync_start_time = int(time.time())
    
    vault_dir = config.get("vault_dir")
    if not vault_dir or not os.path.exists(vault_dir):
        vault_dir = get_default_vault_dir()
        
    status_queue.put("Scanning local notes...")
    local_files = collect_local_files(vault_dir)
    status_queue.put(f"Found {len(local_files)} files locally.")
    
    # 1. Handshake
    init_data = {
        "username": username,
        "password": password,
        "vault": vault,
        "files": local_files,
        "synced_files": synced_files
    }
    status_queue.put("Connecting to server...")
    status, body = http_post(f"{url}/?route=sync.init", init_data, timeout=30)
    
    if status != 200:
        raise Exception(f"Sync handshake failed ({status}): {body}")
        
    try:
        res = json.loads(body)
    except Exception:
        raise Exception(f"Invalid server response: {body}")
        
    if "error" in res:
        raise Exception(res["error"])
        
    to_upload = res.get("to_upload", [])
    to_download = res.get("to_download", [])
    conflicts = res.get("conflicts", [])
    to_delete_on_client = res.get("to_delete_on_client", [])
    deleted_on_server = res.get("deleted_on_server", [])
    
    if deleted_on_server:
        status_queue.put(f"Server cleaned up {len(deleted_on_server)} deleted files.")
        
    # Process local deletions (move to Recycle Bin instead of deleting permanently)
    if to_delete_on_client:
        status_queue.put(f"Syncing deletions: archiving {len(to_delete_on_client)} files to Recycle Bin...")
        recycle_bin_dir = os.path.join(vault_dir, "Recycle Bin")
        for rel_path in to_delete_on_client:
            abs_path = os.path.join(vault_dir, rel_path.replace('/', os.sep))
            if os.path.exists(abs_path):
                try:
                    dest_path = os.path.join(recycle_bin_dir, rel_path.replace('/', os.sep))
                    os.makedirs(os.path.dirname(dest_path), exist_ok=True)
                    
                    # If a file with the same name already exists in the Recycle Bin, append timestamp
                    if os.path.exists(dest_path):
                        base, ext = os.path.splitext(dest_path)
                        dest_path = f"{base}_{int(time.time())}{ext}"
                        
                    os.rename(abs_path, dest_path)
                    status_queue.put(f"Archived to Recycle Bin: {rel_path}")
                except Exception as e:
                    status_queue.put(f"Error archiving local file {rel_path}: {e}")
        prune_empty_dirs(vault_dir)

    # 2. Upload files
    uploaded_count = 0
    if to_upload:
        status_queue.put(f"Uploading {len(to_upload)} new/modified files...")
        for rel_path in to_upload:
            if sync_stop_event.is_set():
                status_queue.put("Sync cancelled by user.")
                return
                
            abs_path = os.path.join(vault_dir, rel_path.replace('/', os.sep))
            if not os.path.exists(abs_path):
                continue
                
            try:
                with open(abs_path, 'rb') as f:
                    content_bytes = f.read()
                content_b64 = base64.b64encode(content_bytes).decode('utf-8')
                mtime = int(os.path.getmtime(abs_path))
                
                upload_data = {
                    "username": username,
                    "password": password,
                    "vault": vault,
                    "path": rel_path,
                    "content": content_b64,
                    "mtime": mtime
                }
                
                status_up, body_up = http_post(f"{url}/?route=sync.upload", upload_data, timeout=30)
                if status_up != 200:
                    raise Exception(f"Upload failed ({status_up}): {body_up}")
                    
                res_up = json.loads(body_up)
                if "error" in res_up:
                    raise Exception(res_up["error"])
                    
                uploaded_count += 1
                if uploaded_count % 5 == 0 or uploaded_count == len(to_upload):
                    status_queue.put(f"Uploaded {uploaded_count}/{len(to_upload)} files...")
            except Exception as e:
                status_queue.put(f"Error uploading {rel_path}: {str(e)}")
                raise e

    # 3. Download files from server (normal overwrite)
    downloaded_count = 0
    if to_download:
        status_queue.put(f"Downloading {len(to_download)} files from server...")
        for rel_path in to_download:
            if sync_stop_event.is_set():
                status_queue.put("Sync cancelled by user.")
                return
                
            try:
                download_data = {
                    "username": username,
                    "password": password,
                    "vault": vault,
                    "path": rel_path
                }
                status_dl, body_dl = http_post(f"{url}/?route=sync.download", download_data, timeout=30)
                if status_dl != 200:
                    raise Exception(f"Download failed ({status_dl}): {body_dl}")
                    
                res_dl = json.loads(body_dl)
                if "error" in res_dl:
                    raise Exception(res_dl["error"])
                    
                server_content = base64.b64decode(res_dl["content"])
                server_mtime = int(res_dl.get("mtime", sync_start_time))
                
                abs_path = os.path.join(vault_dir, rel_path.replace('/', os.sep))
                os.makedirs(os.path.dirname(abs_path), exist_ok=True)
                with open(abs_path, 'wb') as f:
                    f.write(server_content)
                os.utime(abs_path, (server_mtime, server_mtime))
                status_queue.put(f"Downloaded update: {rel_path}")
                downloaded_count += 1
            except Exception as e:
                status_queue.put(f"Error downloading {rel_path}: {str(e)}")
                raise e

    # 4. Download and resolve conflicted files (both changed)
    conflict_count = 0
    if conflicts:
        status_queue.put(f"Resolving {len(conflicts)} conflicted files...")
        for rel_path in conflicts:
            if sync_stop_event.is_set():
                status_queue.put("Sync cancelled by user.")
                return
                
            try:
                download_data = {
                    "username": username,
                    "password": password,
                    "vault": vault,
                    "path": rel_path
                }
                status_dl, body_dl = http_post(f"{url}/?route=sync.download", download_data, timeout=30)
                if status_dl != 200:
                    raise Exception(f"Download failed ({status_dl}): {body_dl}")
                    
                res_dl = json.loads(body_dl)
                if "error" in res_dl:
                    raise Exception(res_dl["error"])
                    
                server_content = base64.b64decode(res_dl["content"])
                server_mtime = int(res_dl.get("mtime", sync_start_time))
                
                abs_path = os.path.join(vault_dir, rel_path.replace('/', os.sep))
                if os.path.exists(abs_path):
                    # Local file exists: rename to V{N}local and save server's version as V{N}web
                    local_rel, web_rel = get_versioned_paths(vault_dir, rel_path)
                    local_abs = os.path.join(vault_dir, local_rel.replace('/', os.sep))
                    web_abs = os.path.join(vault_dir, web_rel.replace('/', os.sep))
                    
                    os.makedirs(os.path.dirname(local_abs), exist_ok=True)
                    os.makedirs(os.path.dirname(web_abs), exist_ok=True)
                    
                    # Preserve local mtime
                    local_mtime = int(os.path.getmtime(abs_path))
                    os.rename(abs_path, local_abs)
                    os.utime(local_abs, (local_mtime, local_mtime))
                    
                    # Write server content to V{N}web
                    with open(web_abs, 'wb') as f:
                        f.write(server_content)
                    os.utime(web_abs, (server_mtime, server_mtime))
                    
                    status_queue.put(f"Conflict resolved: split {rel_path} into {local_rel} and {web_rel}")
                else:
                    # Fallback if local file was deleted in the split-second since scan
                    os.makedirs(os.path.dirname(abs_path), exist_ok=True)
                    with open(abs_path, 'wb') as f:
                        f.write(server_content)
                    os.utime(abs_path, (server_mtime, server_mtime))
                    status_queue.put(f"Downloaded conflicted note: {rel_path}")
                    
                conflict_count += 1
            except Exception as e:
                status_queue.put(f"Error resolving conflict for {rel_path}: {str(e)}")
                raise e

    # 5. Finalize and trigger indexing
    status_queue.put("Indexing notes on server...")
    done_data = {
        "username": username,
        "password": password,
        "vault": vault
    }
    status_done, body_done = http_post(f"{url}/?route=sync.done", done_data, timeout=60)
    if status_done != 200:
        raise Exception(f"Re-indexing failed ({status_done}): {body_done}")
        
    res_done = json.loads(body_done)
    if "error" in res_done:
        raise Exception(res_done["error"])
        
    # Write back the new synced_files manifest
    new_local_files = collect_local_files(vault_dir)
    config["synced_files"] = new_local_files
    save_config_file(config)
    
    stats = res_done.get("stats", {})
    status_queue.put(f"Sync complete! Uploaded: {uploaded_count}. Downloaded: {downloaded_count}. Conflicts resolved: {conflict_count}. Database: Scanned {stats.get('scanned', 0)}, Added {stats.get('added', 0)}, Updated {stats.get('updated', 0)}, Removed {stats.get('removed', 0)}.")

def sync_loop_thread(config):
    interval_mins = max(1, int(config.get("interval", 15)))
    interval_secs = interval_mins * 60
    
    status_queue.put(f"Background sync started. Interval: {interval_mins} min.")
    
    while not sync_stop_event.is_set():
        try:
            status_queue.put("Starting synchronization cycle...")
            perform_sync(config)
            status_queue.put(f"Sync cycle complete. Waiting {interval_mins} mins...")
        except Exception as e:
            status_queue.put(f"Sync cycle error: {str(e)}")
            status_queue.put(f"Will retry in {interval_mins} mins...")
            
        # Sleep in chunks to allow fast response to stop event
        slept = 0
        while slept < interval_secs:
            if sync_stop_event.is_set():
                break
            time.sleep(1)
            slept += 1

class SyncAgentApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Vault Sync Agent")
        self.root.geometry("520x630")
        self.root.minsize(450, 520)
        
        self.style = ttk.Style()
        self.style.theme_use('vista' if 'vista' in self.style.theme_names() else 'default')
        
        self.create_widgets()
        self.load_settings()
        
        # Start queue poller
        self.root.after(100, self.poll_status_queue)
        
        # Intercept window close to minimize or exit nicely
        self.root.protocol("WM_DELETE_WINDOW", self.on_close)

    def create_widgets(self):
        # Main container
        main_frame = ttk.Frame(self.root, padding="15")
        main_frame.pack(fill=tk.BOTH, expand=True)
        
        # Header
        header_label = ttk.Label(main_frame, text="Hrb Notes", font=("Helvetica", 14, "bold"))
        header_label.pack(anchor=tk.W, pady=(0, 2))
        sub_label = ttk.Label(main_frame, text="Local Obsidian Vault Sync Agent", font=("Helvetica", 10, "italic"))
        sub_label.pack(anchor=tk.W, pady=(0, 15))
        
        # Configuration Frame
        config_frame = ttk.LabelFrame(main_frame, text=" Connection Settings ", padding="10")
        config_frame.pack(fill=tk.X, pady=(0, 15))
        
        # Fields
        ttk.Label(config_frame, text="Site URL:").grid(row=0, column=0, sticky=tk.W, pady=4)
        self.entry_url = ttk.Entry(config_frame, width=40)
        self.entry_url.grid(row=0, column=1, columnspan=2, sticky=tk.EW, padx=(10, 0), pady=4)
        
        ttk.Label(config_frame, text="Target Vault Name:").grid(row=1, column=0, sticky=tk.W, pady=4)
        self.entry_vault = ttk.Entry(config_frame, width=40)
        self.entry_vault.grid(row=1, column=1, columnspan=2, sticky=tk.EW, padx=(10, 0), pady=4)
        
        # Vault Directory selection
        ttk.Label(config_frame, text="Vault Folder:").grid(row=2, column=0, sticky=tk.W, pady=4)
        self.entry_vault_dir = ttk.Entry(config_frame, width=30)
        self.entry_vault_dir.grid(row=2, column=1, sticky=tk.EW, padx=(10, 0), pady=4)
        self.btn_browse = ttk.Button(config_frame, text="Browse...", command=self.browse_vault_dir, width=8)
        self.btn_browse.grid(row=2, column=2, sticky=tk.E, padx=(5, 0), pady=4)
        
        ttk.Label(config_frame, text="Admin ID:").grid(row=3, column=0, sticky=tk.W, pady=4)
        self.entry_user = ttk.Entry(config_frame, width=40)
        self.entry_user.grid(row=3, column=1, columnspan=2, sticky=tk.EW, padx=(10, 0), pady=4)
        
        ttk.Label(config_frame, text="Admin Password:").grid(row=4, column=0, sticky=tk.W, pady=4)
        self.entry_pass = ttk.Entry(config_frame, width=40, show="*")
        self.entry_pass.grid(row=4, column=1, columnspan=2, sticky=tk.EW, padx=(10, 0), pady=4)
        
        ttk.Label(config_frame, text="Interval (Minutes):").grid(row=5, column=0, sticky=tk.W, pady=4)
        self.entry_interval = ttk.Entry(config_frame, width=40)
        self.entry_interval.grid(row=5, column=1, columnspan=2, sticky=tk.EW, padx=(10, 0), pady=4)
        
        config_frame.columnconfigure(1, weight=1)
        config_frame.columnconfigure(2, weight=0)
        
        # Startup Option
        self.var_startup = tk.BooleanVar(value=False)
        self.chk_startup = ttk.Checkbutton(main_frame, text="Run automatically on Windows startup", variable=self.var_startup)
        if WINDOWS_SYSTEM:
            self.chk_startup.pack(anchor=tk.W, pady=(0, 15))
        
        # Operations Buttons
        btn_frame = ttk.Frame(main_frame)
        btn_frame.pack(fill=tk.X, pady=(0, 15))
        
        self.btn_start = ttk.Button(btn_frame, text="Save & Start Sync", command=self.start_sync)
        self.btn_start.pack(side=tk.LEFT, padx=(0, 10))
        
        self.btn_stop = ttk.Button(btn_frame, text="Stop Sync", command=self.stop_sync, state=tk.DISABLED)
        self.btn_stop.pack(side=tk.LEFT, padx=(0, 10))
        
        self.btn_now = ttk.Button(btn_frame, text="Sync Now", command=self.sync_now)
        self.btn_now.pack(side=tk.LEFT)
        
        # Log/Status Frame
        log_frame = ttk.LabelFrame(main_frame, text=" Activity Log ", padding="10")
        log_frame.pack(fill=tk.BOTH, expand=True)
        
        self.log_area = scrolledtext.ScrolledText(log_frame, height=10, font=("Consolas", 9), wrap=tk.WORD)
        self.log_area.pack(fill=tk.BOTH, expand=True)
        self.log_area.config(state=tk.DISABLED)

    def log(self, message):
        self.log_area.config(state=tk.NORMAL)
        self.log_area.insert(tk.END, f"[{time.strftime('%H:%M:%S')}] {message}\n")
        self.log_area.see(tk.END)
        self.log_area.config(state=tk.DISABLED)

    def browse_vault_dir(self):
        curr_dir = self.entry_vault_dir.get().strip() or get_default_vault_dir()
        selected = filedialog.askdirectory(
            parent=self.root,
            title="Select Local Vault Directory",
            initialdir=curr_dir
        )
        if selected:
            selected = os.path.normpath(selected)
            self.entry_vault_dir.delete(0, tk.END)
            self.entry_vault_dir.insert(0, selected)

    def load_settings(self):
        config_path = get_config_path()
        if os.path.exists(config_path):
            try:
                with open(config_path, 'r') as f:
                    config = json.load(f)
                self.entry_url.insert(0, config.get("url", ""))
                self.entry_vault.insert(0, config.get("vault", ""))
                
                # Load vault_dir, default to get_default_vault_dir() if empty
                vault_dir = config.get("vault_dir", "")
                if not vault_dir:
                    vault_dir = get_default_vault_dir()
                self.entry_vault_dir.insert(0, vault_dir)
                
                self.entry_user.insert(0, config.get("username", ""))
                self.entry_pass.insert(0, config.get("password", ""))
                self.entry_interval.insert(0, str(config.get("interval", 15)))
                self.var_startup.set(config.get("startup", False))
                self.log("Settings loaded from config file.")
            except Exception as e:
                self.log(f"Error loading settings: {str(e)}")
        else:
            self.entry_vault_dir.insert(0, get_default_vault_dir())

    def save_settings(self):
        # Read synced_files from existing config if available
        synced_files = {}
        config_path = get_config_path()
        if os.path.exists(config_path):
            try:
                with open(config_path, 'r') as f:
                    old_config = json.load(f)
                    synced_files = old_config.get("synced_files", {})
            except Exception:
                pass

        vault_dir = self.entry_vault_dir.get().strip()
        if not vault_dir:
            vault_dir = get_default_vault_dir()

        config = {
            "url": self.entry_url.get().strip(),
            "vault": self.entry_vault.get().strip(),
            "vault_dir": vault_dir,
            "username": self.entry_user.get().strip(),
            "password": self.entry_pass.get(),
            "interval": int(self.entry_interval.get().strip() or "15"),
            "startup": self.var_startup.get(),
            "synced_files": synced_files
        }
        
        try:
            with open(config_path, 'w') as f:
                json.dump(config, f, indent=4)
            self.log("Settings saved to file.")
            
            # Save Windows startup registry key
            if WINDOWS_SYSTEM:
                success, msg = set_startup_registry(config["startup"])
                self.log(msg)
                
            return config
        except Exception as e:
            messagebox.showerror("Error", f"Could not save settings: {str(e)}")
            return None

    def start_sync(self):
        global sync_thread
        
        # Validate input
        url = self.entry_url.get().strip()
        vault = self.entry_vault.get().strip()
        vault_dir = self.entry_vault_dir.get().strip()
        username = self.entry_user.get().strip()
        password = self.entry_pass.get()
        interval = self.entry_interval.get().strip()
        
        if not (url and vault and vault_dir and username and password and interval):
            messagebox.showwarning("Incomplete Fields", "Please populate all connection settings.")
            return
            
        try:
            int(interval)
        except ValueError:
            messagebox.showwarning("Invalid Interval", "Interval must be a valid integer number of minutes.")
            return

        config = self.save_settings()
        if not config:
            return
            
        # Stop existing thread
        self.stop_sync(silent=True)
        
        sync_stop_event.clear()
        sync_thread = threading.Thread(target=sync_loop_thread, args=(config,), daemon=True)
        sync_thread.start()
        
        self.btn_start.config(state=tk.DISABLED)
        self.btn_stop.config(state=tk.NORMAL)
        self.btn_now.config(state=tk.NORMAL)

    def stop_sync(self, silent=False):
        global sync_thread
        sync_stop_event.set()
        
        if sync_thread and sync_thread.is_alive():
            if not silent:
                self.log("Stopping sync service...")
            sync_thread.join(timeout=2)
            
        self.btn_start.config(state=tk.NORMAL)
        self.btn_stop.config(state=tk.DISABLED)
        if not silent:
            self.log("Sync service stopped.")

    def sync_now(self):
        # Trigger immediate manual sync cycle in a background thread to prevent UI freezing
        url = self.entry_url.get().strip()
        vault = self.entry_vault.get().strip()
        vault_dir = self.entry_vault_dir.get().strip() or get_default_vault_dir()
        username = self.entry_user.get().strip()
        password = self.entry_pass.get()
        
        if not (url and vault and username and password):
            messagebox.showwarning("Incomplete Fields", "Please fill in the URL, Vault, and Credentials to sync.")
            return

        # Load synced_files from existing file if possible
        synced_files = {}
        config_path = get_config_path()
        if os.path.exists(config_path):
            try:
                with open(config_path, 'r') as f:
                    old_config = json.load(f)
                    synced_files = old_config.get("synced_files", {})
            except Exception:
                pass

        config = {
            "url": url,
            "vault": vault,
            "vault_dir": vault_dir,
            "username": username,
            "password": password,
            "synced_files": synced_files
        }
        
        self.btn_now.config(state=tk.DISABLED)
        self.log("Manual sync initiated...")
        
        def runner():
            try:
                perform_sync(config)
                status_queue.put("Manual sync completed successfully.")
            except Exception as e:
                status_queue.put(f"Sync error: {str(e)}")
            finally:
                self.root.after(0, lambda: self.btn_now.config(state=tk.NORMAL))
                
        threading.Thread(target=runner, daemon=True).start()

    def poll_status_queue(self):
        try:
            while True:
                msg = status_queue.get_nowait()
                self.log(msg)
                status_queue.task_done()
        except queue.Empty:
            pass
        self.root.after(100, self.poll_status_queue)

    def on_close(self):
        # Simply exit on window close, but log stopping first
        self.stop_sync(silent=True)
        self.root.destroy()

if __name__ == "__main__":
    # Autostart / minimized behavior
    config_path = get_config_path()
    start_background = False
    
    if "--minimized" in sys.argv:
        start_background = True
        
    root = tk.Tk()
    app = SyncAgentApp(root)
    
    if start_background:
        if os.path.exists(config_path):
            try:
                # Iconify window
                root.iconify()
                # Run sync
                app.start_sync()
            except Exception:
                pass
                
    root.mainloop()
