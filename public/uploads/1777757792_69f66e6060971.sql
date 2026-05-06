CREATE DATABASE IF NOT EXISTS qlbanhang CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE qlbanhang;

-- 1. Bảng Loại Hàng
CREATE TABLE IF NOT EXISTS loaihang (
    maloai VARCHAR(10) NOT NULL PRIMARY KEY,
    tenloai VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Bảng Nhà Cung Cấp
CREATE TABLE IF NOT EXISTS nhacungcap (
    mancc VARCHAR(10) NOT NULL PRIMARY KEY,
    tenncc VARCHAR(150) NOT NULL,
    sdt VARCHAR(15)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Bảng Hàng Hóa
CREATE TABLE IF NOT EXISTS hanghoa (
    mahang VARCHAR(10) NOT NULL PRIMARY KEY,
    tenhang VARCHAR(150) NOT NULL,
    dongia DECIMAL(15,2) NOT NULL,
    soluong INT DEFAULT 0,
    maloai VARCHAR(10),
    mancc VARCHAR(10),
    
    CONSTRAINT fk_hh_loaihang FOREIGN KEY (maloai) REFERENCES loaihang(maloai) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_hh_ncc FOREIGN KEY (mancc) REFERENCES nhacungcap(mancc) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- DỮ LIỆU MẪU
INSERT INTO loaihang (maloai, tenloai) VALUES ('L01', 'Điện tử'), ('L02', 'Thời trang');
INSERT INTO nhacungcap (mancc, tenncc, sdt) VALUES ('NCC01', 'Công ty Samsung', '0901234567'), ('NCC02', 'Xưởng may An Phước', '0912345678');
INSERT INTO hanghoa (mahang, tenhang, dongia, soluong, maloai, mancc) VALUES 
('H001', 'Điện thoại Galaxy S24', 24000000, 10, 'L01', 'NCC01'),
('H002', 'Áo sơ mi nam', 450000, 50, 'L02', 'NCC02');