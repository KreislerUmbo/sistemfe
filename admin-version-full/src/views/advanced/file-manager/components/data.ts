import type {
  DocumentType,
  FolderType,
} from "@/views/advanced/file-manager/components/types";

import gdrive from "@/assets/images/logos/lang-logo/gdrive.png";
import dropbox from "@/assets/images/logos/lang-logo/dropbox.png";
import onedrive from "@/assets/images/logos/lang-logo/onedrive.png";
import server from "@/assets/images/logos/lang-logo/server.png";

import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar6 from "@/assets/images/users/avatar-6.jpg";
import avatar7 from "@/assets/images/users/avatar-7.jpg";
import avatar8 from "@/assets/images/users/avatar-8.jpg";
import avatar10 from "@/assets/images/users/avatar-10.jpg";

export const folderList: FolderType[] = [
  {
    title: "Google Drive",
    image: gdrive,
    files: 34,
    storage: "500",
    progress: 38,
  },
  {
    title: "Dropbox",
    image: dropbox,
    files: 68,
    storage: "500",
    progress: 15,
  },
  {
    title: "Onedrive",
    image: onedrive,
    files: 192,
    storage: "500",
    progress: 48,
  },
  {
    title: "Server",
    image: server,
    files: 81,
    storage: "500",
    progress: 76,
  },
];

export const documentList: DocumentType[] = [
  {
    file_name: "payment.pdf",
    date: "18 Jul 2024",
    size: "2.3 MB",
    avatars: [avatar2, avatar5, avatar3],
  },
  {
    file_name: "statement.pdf",
    date: "08 Dec 2024",
    size: "3.7 MB",
    avatars: [avatar3, avatar10],
  },
  {
    file_name: "idcard.pdf",
    date: "30 Nov 2024",
    size: "1.5 MB",
    avatars: [avatar7, avatar2],
  },
  {
    file_name: "invoice.pdf",
    date: "09 Sep 2024",
    size: "3.2 MB",
    avatars: [],
  },
  {
    file_name: "tutorial.pdf",
    date: "14 Aug 2024",
    size: "12.7 MB",
    avatars: [avatar2, avatar3, avatar8],
  },
  {
    file_name: "project.pdf",
    date: "12 Aug 2024",
    size: "5.2 MB",
    avatars: [avatar1, avatar4, avatar6],
  },
];

export const imageList: DocumentType[] = [
  {
    file_name: "img52315.jpeg",
    date: "18 Jul 2024",
    size: "2.3 MB",
    avatars: [avatar2, avatar5, avatar3],
  },
  {
    file_name: "img63695.jpeg",
    date: "08 Dec 2024",
    size: "3.7 MB",
    avatars: [avatar3, avatar10],
  },
  {
    file_name: "img00021.jpeg",
    date: "30 Nov 2024",
    size: "1.5 MB",
    avatars: [avatar7, avatar2],
  },
  {
    file_name: "img36251.jpeg",
    date: "09 Sep 2024",
    size: "3.2 MB",
    avatars: [],
  },
  {
    file_name: "img362511.jpeg",
    date: "14 Aug 2024",
    size: "12.7 MB",
    avatars: [avatar2, avatar3, avatar8],
  },
  {
    file_name: "img963852.jpeg",
    date: "12 Aug 2024",
    size: "5.2 MB",
    avatars: [avatar1, avatar4, avatar6],
  },
];

export const audioList: DocumentType[] = [
  {
    file_name: "audio52315..",
    date: "18 Jul 2024",
    size: "2.3 MB",
  },
  {
    file_name: "audio63695..",
    date: "08 Dec 2024",
    size: "3.7 MB",
  },
  {
    file_name: "audio00021..",
    date: "30 Nov 2024",
    size: "1.5 MB",
  },
  {
    file_name: "audio36251..",
    date: "09 Sep 2024",
    size: "3.2 MB",
  },
  {
    file_name: "audio362511..",
    date: "14 Aug 2024",
    size: "12.7 MB",
  },
  {
    file_name: "audio963852..",
    date: "12 Aug 2024",
    size: "5.2 MB",
  },
];
