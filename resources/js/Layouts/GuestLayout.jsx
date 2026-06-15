import { Grid } from '@mui/material';
import BgImage from '@/Images/LoginBgImage.png';
import Logo from '@/Images/Logo.png';
import LogoBgVideo from '@/Images/bg-login--video.mp4';

export default function GuestLayout({ children }) {
  return (
    <div className="min-h-screen bg-[#1a1f2e]">
      <Grid container spacing={0} style={{ minHeight: '100vh' }}>
        {/* Left panel - Logo section (5 columns) */}
        <Grid
          item
          size={{ xs: 5 }}
          display={{xs:'none', md:"flex"}}
          className="bg-[#F01F32] flex items-center justify-center"
        >
          <img src={Logo} alt="logo" className="w-48 h-48 object-contain" />
        </Grid>

        {/* Right panel - Video background section (7 columns) */}
        <Grid
          item
          size={{ xs: 12, md: 7 }}
          padding={{xs:3, md:0}}
          className="relative flex items-center justify-center overflow-hidden"
          style={{
            position: 'relative',
            backgroundColor: '#000',
          }}
        >
          {/* Background Video */}
          <video
            autoPlay
            loop
            muted
            playsInline
            className="absolute top-0 left-0 w-full h-full object-cover"
          >
            <source src={LogoBgVideo} type="video/mp4" />
          </video>

          {/* Optional dark overlay for better contrast */}
          <div className="absolute inset-0 bg-black/40"></div>

          {/* Foreground content */}
          <div className="relative z-10 flex items-center justify-center w-full h-full">
            {children}
          </div>
        </Grid>
      </Grid>
    </div>
  );
}
