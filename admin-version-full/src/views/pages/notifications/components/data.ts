import type { NotificationType } from "@/views/pages/notifications/components/types";
import avatar1 from "@/assets/images/users/avatar-1.jpg";
import avatar2 from "@/assets/images/users/avatar-2.jpg";
import avatar3 from "@/assets/images/users/avatar-3.jpg";
import avatar4 from "@/assets/images/users/avatar-4.jpg";
import avatar5 from "@/assets/images/users/avatar-5.jpg";
import avatar7 from "@/assets/images/users/avatar-7.jpg";
import avatar8 from "@/assets/images/users/avatar-8.jpg";
import avatar9 from "@/assets/images/users/avatar-9.jpg";
import avatar10 from "@/assets/images/users/avatar-10.jpg";

export const notificationsList: NotificationType[] = [
  {
    date: "Today",
    count: 2,
    events: [
      {
        title: "Appointment with Charles Reiter",
        time: "01:30 PM",
        description:
          "Please ensure you have all necessary documents or items required for the appointment",
        avatar: avatar10,
      },
      {
        title: "New Session booked by Joseph Rust",
        time: "10:37 AM",
        description:
          "Please confirm this appointment and let us know if you have any preferences or special requirements. Looking forward to our session together!",
        avatar: avatar1,
      },
      {
        title: "Payment Not Added",
        time: "04:10 AM",
        description:
          "This is to inform you that your recent payment has not been successfully processed. Please review your payment method and ensure that sufficient funds are available or that the provided details are accurate.",
        avatar: avatar9,
      },
    ],
  },
  {
    date: "Yesterday",
    count: 5,
    events: [
      {
        title: "Password change email sent",
        time: "07:45 PM",
        description:
          "This is to inform you that your password has been successfully changed for your account.",
        avatar: avatar2,
      },
      {
        title: "Meeting at 07:45 PM",
        time: "02:05 PM",
        description:
          "Meeting Reminder: Just a quick heads-up about your meeting tonight at 07:45 PM. Don't forget to prep any necessary materials and jot down any questions or topics you'd like to discuss.",
        avatar: avatar3,
      },
      {
        title: "Payment Not Added",
        time: "11:15 AM",
        description:
          "This is to inform you that your recent payment has not been successfully processed. Please review your payment method and ensure that sufficient funds are available or that the provided details are accurate.",
        avatar: avatar4,
      },
      {
        title: "Payment Not Added",
        time: "09:44 AM",
        description:
          "This is to inform you that your recent payment has not been successfully processed. Please review your payment method and ensure that sufficient funds are available or that the provided details are accurate.",
        avatar: avatar8,
      },
    ],
  },
  {
    date: "01 June 2024",
    count: 8,
    events: [
      {
        title: "New system upgrade",
        time: "01:30 PM",
        description:
          "During this time, access may be temporarily limited. We appreciate your patience and understanding as we work to improve your experience. Stay tuned for more details!",
        avatar: avatar5,
      },
      {
        title: "New Session booked by Joseph Rust",
        time: "08:05 AM",
        description:
          "Please confirm this appointment and let us know if you have any preferences or special requirements. Looking forward to our session together!",
        avatar: avatar7,
      },
    ],
  },
];
