import platform
import uuid
import sys

def get_hardware_id():
    system = platform.system()
    if system == "Windows":
        try:
            import wmi
            c = wmi.WMI()
            motherboard = c.Win32_BaseBoard()[0]
            return motherboard.SerialNumber.strip()
        except ImportError:
            print("A biblioteca 'WMI' é necessária no Windows. Instale-a com 'pip install WMI'.")
            sys.exit(1)
    elif system == "Linux" or system == "Darwin":
        return str(uuid.getnode())  # Obtém o MAC address como hardware ID
    else:
        print(f"Sistema operacional {system} não suportado.")
        sys.exit(1)

def main():
    hardware_id = get_hardware_id()
    print(f"Hardware ID: {hardware_id}")

if __name__ == "__main__":
    main()

